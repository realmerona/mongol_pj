import json
import os
import sqlite3
import unittest
from datetime import datetime
from unittest.mock import patch

from scripts.utils import db
from scripts.utils import notifications
from scripts.utils.notifications import sendAppriseNotifications

from tests.helpers import Settings


class TestAppriseNotifications(unittest.TestCase):

    def setUp(self):
        db.DB_PATH = self.db_file
        db._DB = None
        # In-memory state must not leak between tests
        notifications.species_last_notified = {}
        notifications.species_last_detected = {}
        notifications.SEASONAL_CACHE = "test_seasonal_cache.json"

    @classmethod
    def setUpClass(cls):
        cls.db_file = "test.db"
        cls.apprise_body_file = "test_apprise_body"
        cls.apprise_config_file = "test_apprise_config"

    def create_test_db(self):
        """ create a database connection to a SQLite database """
        conn = None
        try:
            conn = sqlite3.connect(self.db_file)
            sql_create_detections_table = """ CREATE TABLE IF NOT EXISTS detections (
                                            id integer PRIMARY KEY,
                                            Sci_Name text NOT NULL,
                                            Com_Name text NOT NULL,
                                            Date date NOT NULL,
                                            Time time NULL
                                        ); """
            cur = conn.cursor()
            cur.execute(sql_create_detections_table)
            sql = ''' INSERT INTO detections(Sci_Name, Com_Name, Date)
                  VALUES(?,?,?) '''

            today = datetime.now().strftime("%Y-%m-%d")  # SQLite stores date as YYYY-MM-DD
            cur.execute(sql, ["Myiarchus crinitus", "Great Crested Flycatcher", today])
            conn.commit()

        except Exception as e:
            print(e)
        finally:
            if conn:
                conn.close()

    def create_apprise_config(self):
        with open(self.apprise_body_file, 'w') as f:
            f.write('A $comname ($sciname) was just detected with a confidence of $confidencepct ($reason)')
        with open(self.apprise_config_file, 'w') as f:
            f.write('a dummy config')
        notifications.APPRISE_BODY = self.apprise_body_file
        notifications.APPRISE_CONFIG = self.apprise_config_file

    def tearDown(self):
        if db._DB is not None:
            db._DB.close()
            db._DB = None
        if os.path.exists(self.db_file):
            os.remove(self.db_file)
        if os.path.exists(self.apprise_body_file):
            os.remove(self.apprise_body_file)
        if os.path.exists(self.apprise_config_file):
            os.remove(self.apprise_config_file)
        if os.path.exists("test_seasonal_cache.json"):
            os.remove("test_seasonal_cache.json")

    def create_species_prefs(self, sci_name, muted=0, notify_mode='default'):
        conn = sqlite3.connect(self.db_file)
        cur = conn.cursor()
        cur.execute("""CREATE TABLE IF NOT EXISTS species_prefs (
            sci_name text PRIMARY KEY, com_name text,
            favorite integer DEFAULT 0, muted integer DEFAULT 0,
            notify_mode text DEFAULT 'default',
            custom_threshold real, crowned_clip text, updated_at text)""")
        cur.execute("INSERT OR REPLACE INTO species_prefs (sci_name, muted, notify_mode) VALUES (?,?,?)",
                    [sci_name, muted, notify_mode])
        conn.commit()
        conn.close()

    def create_seasonal_cache(self, sci_name, score):
        with open("test_seasonal_cache.json", 'w') as f:
            json.dump({"lat": 50, "lon": 5, "version": 1, "data": {sci_name: [score] * 48}}, f)

    def get_default_params(self):
        return {
            "sci_name": "Myiarchus crinitus",
            "com_name": "Great Crested Flycatcher",
            "confidence": "0.91",
            "confidencepct": "91",
            "path": "filename",
            "date": "1666-06-06",
            "time_of_day": "06:06:06",
            "week": "06",
            "latitude": "-1",
            "longitude": "-1",
            "cutoff": "0.7",
            "sens": "1.25",
            "overlap": "0.0"
        }

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_notifications(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        notifications.DB_PATH = self.db_file
        settings_dict = Settings.with_defaults()

        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())

        # No active apprise notifications configured. Confirm no notifications.
        self.assertEqual(mock_notify.call_count, 0)

        # Add daily notification.
        mock_notify.reset_mock()
        settings_dict["APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY"] = "1"
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())

        self.assertEqual(mock_notify.call_count, 1)
        self.assertEqual(
            mock_notify.call_args_list[0][0][0],
            "A Great Crested Flycatcher (Myiarchus crinitus) was just detected with a confidence of 91 (first time today)"
        )

        # Add new species notification.
        mock_notify.reset_mock()
        settings_dict["APPRISE_NOTIFY_NEW_SPECIES"] = "1"
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())

        self.assertEqual(mock_notify.call_count, 2)
        self.assertEqual(
            mock_notify.call_args_list[0][0][0],
            "A Great Crested Flycatcher (Myiarchus crinitus) was just detected with a confidence of 91 (first time today)"
        )
        self.assertEqual(
            mock_notify.call_args_list[1][0][0],
            "A Great Crested Flycatcher (Myiarchus crinitus) was just detected with a confidence of 91 (only seen 1 times in last 7d)"
        )

        # Add each species notification. Visit grouping would suppress the
        # repeat within the same visit, so test the legacy per-chirp mode.
        mock_notify.reset_mock()
        settings_dict["APPRISE_NOTIFY_EACH_DETECTION"] = "1"
        settings_dict["APPRISE_VISIT_GROUPING"] = "0"
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())

        self.assertEqual(mock_notify.call_count, 3)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_notifications_excluded(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        notifications.DB_PATH = self.db_file
        settings_dict = Settings.with_defaults()
        settings_dict["APPRISE_NOTIFY_EACH_DETECTION"] = "1"
        # Legacy per-chirp mode: this test exercises the exclude list with
        # consecutive calls that visit grouping would otherwise suppress.
        settings_dict["APPRISE_VISIT_GROUPING"] = "0"

        settings_dict['APPRISE_ONLY_NOTIFY_SPECIES_NAMES'] = 'Quailfinch'
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())
        # Not excluded. Confirm notifications.
        self.assertEqual(mock_notify.call_count, 1)

        mock_notify.reset_mock()
        settings_dict['APPRISE_ONLY_NOTIFY_SPECIES_NAMES'] = 'Quailfinch,'
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())
        # Not excluded. Confirm notifications.
        self.assertEqual(mock_notify.call_count, 1)

        mock_notify.reset_mock()
        settings_dict['APPRISE_ONLY_NOTIFY_SPECIES_NAMES'] = 'Quailfinch,Great Crested Flycatcher'
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())

        self.assertEqual(mock_notify.call_count, 0)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_notifications_included(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        notifications.DB_PATH = self.db_file
        settings_dict = Settings.with_defaults()
        settings_dict["APPRISE_NOTIFY_EACH_DETECTION"] = "1"
        # Legacy per-chirp mode (see test_notifications_excluded)
        settings_dict["APPRISE_VISIT_GROUPING"] = "0"

        settings_dict['APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2'] = 'Quailfinch'
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())
        # No wanted species. Confirm no notifications.
        self.assertEqual(mock_notify.call_count, 0)

        settings_dict['APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2'] = 'Quailfinch,'
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())
        # No wanted species. Confirm no notifications.
        self.assertEqual(mock_notify.call_count, 0)

        mock_notify.reset_mock()
        settings_dict['APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2'] = 'Quailfinch,Great Crested Flycatcher'
        mock_load_settings.return_value = settings_dict
        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_visit_grouping(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        settings_dict = Settings.with_defaults()
        settings_dict["APPRISE_NOTIFY_EACH_DETECTION"] = "1"
        mock_load_settings.return_value = settings_dict

        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)
        self.assertIn("(new visit)", mock_notify.call_args_list[0][0][0])

        # A second detection moments later belongs to the same visit
        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)

        # Once the quiet gap has passed, the next detection opens a new visit
        notifications.species_last_detected["Great Crested Flycatcher"] -= 600
        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 2)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_muted_species(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        self.create_species_prefs("Myiarchus crinitus", muted=1)
        settings_dict = Settings.with_defaults()
        settings_dict["APPRISE_NOTIFY_EACH_DETECTION"] = "1"
        mock_load_settings.return_value = settings_dict

        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 0)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_notify_mode_every_visit(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        # Per-species mode notifies even with every station-wide rule off
        self.create_species_prefs("Myiarchus crinitus", notify_mode='every_visit')
        mock_load_settings.return_value = Settings.with_defaults()

        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)
        self.assertIn("(new visit)", mock_notify.call_args_list[0][0][0])

        # Same visit: suppressed
        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_notify_mode_never(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        self.create_species_prefs("Myiarchus crinitus", notify_mode='never')
        settings_dict = Settings.with_defaults()
        settings_dict["APPRISE_NOTIFY_EACH_DETECTION"] = "1"
        mock_load_settings.return_value = settings_dict

        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 0)

    @patch('scripts.utils.helpers._load_settings')
    @patch('scripts.utils.notifications.notify')
    def test_rare_alerts(self, mock_notify, mock_load_settings):
        self.create_test_db()
        self.create_apprise_config()
        settings_dict = Settings.with_defaults()
        settings_dict["APPRISE_NOTIFY_RARE"] = "1"
        mock_load_settings.return_value = settings_dict

        # Region-rare: location model expects (almost) none this week
        self.create_seasonal_cache("Myiarchus crinitus", 0.001)
        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)
        self.assertIn("rare for your area this week", mock_notify.call_args_list[0][0][0])

        # Common for the region: falls through to yard rarity (1 lifetime row)
        mock_notify.reset_mock()
        notifications.species_last_detected = {}
        self.create_seasonal_cache("Myiarchus crinitus", 0.9)
        sendAppriseNotifications(**self.get_default_params())
        self.assertEqual(mock_notify.call_count, 1)
        self.assertIn("rare visitor", mock_notify.call_args_list[0][0][0])

    def test_quiet_hours(self):
        settings = Settings.with_defaults()
        self.assertFalse(notifications.in_quiet_hours(settings, hour=23))

        settings["APPRISE_QUIET_HOURS_START"] = "22"
        settings["APPRISE_QUIET_HOURS_END"] = "6"
        self.assertTrue(notifications.in_quiet_hours(settings, hour=23))
        self.assertTrue(notifications.in_quiet_hours(settings, hour=2))
        self.assertFalse(notifications.in_quiet_hours(settings, hour=12))

        # Non-wrapping range
        settings["APPRISE_QUIET_HOURS_START"] = "9"
        settings["APPRISE_QUIET_HOURS_END"] = "17"
        self.assertTrue(notifications.in_quiet_hours(settings, hour=12))
        self.assertFalse(notifications.in_quiet_hours(settings, hour=20))


if __name__ == '__main__':
    unittest.main()
