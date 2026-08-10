<?php
// Seeds scripts/birds.db with realistic demo data for local development and
// UI testing. Run from the repo root with a PHP CLI:
//   php tests/seed_demo_db.php
// Idempotent: wipes and recreates the detections + weather tables each run.
// The database file is gitignored (scripts/*.db), so this never touches a
// real station's data unless you run it there on purpose.

$db_path = __DIR__ . '/../scripts/birds.db';
$db = new SQLite3($db_path);
$db->busyTimeout(2000);

// Safety guard: refuse to destroy what looks like a real station database.
// A freshly created dev database is empty; a real Pi has thousands of rows.
$has_table = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='detections'");
if ($has_table) {
  $existing_rows = (int) $db->querySingle('SELECT COUNT(*) FROM detections');
  $force = isset($argv) && in_array('--force', $argv);
  if ($existing_rows > 500 && !$force) {
    fwrite(STDERR, "REFUSING TO RUN: the detections table already holds $existing_rows rows -\n");
    fwrite(STDERR, "this looks like a real station database, and seeding would erase it.\n");
    fwrite(STDERR, "If you truly want to destroy this data, re-run with --force.\n");
    exit(1);
  }
}

$db->exec('DROP TABLE IF EXISTS detections');
$db->exec('CREATE TABLE IF NOT EXISTS detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL)');
$db->exec('CREATE INDEX "detections_Com_Name" ON "detections" ("Com_Name")');
$db->exec('CREATE INDEX "detections_Sci_Name" ON "detections" ("Sci_Name")');
$db->exec('CREATE INDEX "detections_Date_Time" ON "detections" ("Date" DESC, "Time" DESC)');
$db->exec('CREATE INDEX "detections_Sci_Name_Date" ON "detections" ("Sci_Name", "Date")');
$db->exec('CREATE INDEX "detections_Date_Sci_Name" ON "detections" ("Date", "Sci_Name")');

$db->exec('DROP TABLE IF EXISTS weather');
$db->exec('CREATE TABLE IF NOT EXISTS weather (
  Date DATE,
  Hour INT,
  Temp FLOAT,
  ConditionCode INT,
  IsDay INT,
  WindSpeed FLOAT,
  WindDirection INT,
  PRIMARY KEY(Date, Hour))');

// Data spine tables (kept in sync with createdb.sh / update_birdnet_snippets.sh).
// Recreated empty each run so review/prefs/notes tests start from scratch.
$db->exec('DROP TABLE IF EXISTS detection_reviews');
$db->exec('DROP TABLE IF EXISTS species_prefs');
$db->exec('DROP TABLE IF EXISTS notes');
require_once __DIR__ . '/../scripts/spine_schema.php';
foreach (spine_schema_statements_standalone() as $spine_sql) {
  $db->exec($spine_sql);
}

// [common name, scientific name, relative abundance, dawn-biased?]
$species = [
  ['Carolina Wren', 'Thryothorus ludovicianus', 10, true],
  ['Northern Cardinal', 'Cardinalis cardinalis', 9, true],
  ['American Robin', 'Turdus migratorius', 8, true],
  ['Blue Jay', 'Cyanocitta cristata', 7, false],
  ['Tufted Titmouse', 'Baeolophus bicolor', 6, true],
  ['Carolina Chickadee', 'Poecile carolinensis', 6, true],
  ['Mourning Dove', 'Zenaida macroura', 5, false],
  ['House Finch', 'Haemorhous mexicanus', 5, false],
  ['Red-bellied Woodpecker', 'Melanerpes carolinus', 4, false],
  ['White-breasted Nuthatch', 'Sitta carolinensis', 3, false],
  ['Gray Catbird', 'Dumetella carolinensis', 2, true],
  ['Barred Owl', 'Strix varia', 1, false], // nocturnal
  ['Red-breasted Nuthatch', 'Sitta canadensis', 1, false], // rare visitor
];

mt_srand(20260610); // deterministic output

$days = 35;
$lat = 40.030;
$lon = -75.020;
$insert = $db->prepare('INSERT INTO detections VALUES (:d, :t, :sci, :com, :conf, :lat, :lon, 0.0, :week, 1.25, 0.0, :file)');
$total = 0;

$db->exec('BEGIN');
for ($day = $days; $day >= 0; $day--) {
  $date = date('Y-m-d', strtotime("-$day days"));
  $week = (int) ceil(((int) date('z', strtotime($date)) + 1) / 7.0 * (48.0 / 52.0));
  $day_activity = 0.6 + mt_rand(0, 80) / 100.0; // day-to-day variation

  foreach ($species as $idx => $sp) {
    list($com, $sci, $abundance, $dawn) = $sp;
    if ($com === 'Red-breasted Nuthatch' && $day > 3) continue; // new arrival in last 3 days
    if ($com === 'Gray Catbird' && $day < 16) continue;         // gone quiet 16 days ago
    $n = (int) round($abundance * $day_activity * (2 + mt_rand(0, 3)));
    for ($i = 0; $i < $n; $i++) {
      if ($com === 'Barred Owl') {
        $hour = mt_rand(0, 1) ? mt_rand(0, 4) : mt_rand(21, 23); // nocturnal
      } elseif ($dawn) {
        $hour = max(0, min(23, (int) round(6 + (mt_rand(-300, 900) / 100.0)))); // dawn-biased
      } else {
        $hour = mt_rand(6, 19);
      }
      $min = mt_rand(0, 59);
      $sec = mt_rand(0, 59);
      $time = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
      $conf = mt_rand(60, 99) / 100.0;
      $file = str_replace(' ', '_', $com) . '-' . (int) round($conf * 100) . '-' . $date . '-birdnet-' . str_replace(':', '_', $time) . '.mp3';
      $insert->reset();
      $insert->bindValue(':d', $date, SQLITE3_TEXT);
      $insert->bindValue(':t', $time, SQLITE3_TEXT);
      $insert->bindValue(':sci', $sci, SQLITE3_TEXT);
      $insert->bindValue(':com', $com, SQLITE3_TEXT);
      $insert->bindValue(':conf', $conf, SQLITE3_FLOAT);
      $insert->bindValue(':lat', $lat, SQLITE3_FLOAT);
      $insert->bindValue(':lon', $lon, SQLITE3_FLOAT);
      $insert->bindValue(':week', $week, SQLITE3_INTEGER);
      $insert->bindValue(':file', $file, SQLITE3_TEXT);
      $insert->execute();
      $total++;
    }
  }

  // Hourly weather: simple diurnal temperature curve + occasional rain
  $base_temp = 55 + mt_rand(-10, 15);
  $rainy = mt_rand(0, 5) === 0;
  for ($h = 0; $h < 24; $h++) {
    $temp = $base_temp + 12 * sin(M_PI * max(0, $h - 6) / 14.0);
    $code = $rainy && $h >= 10 && $h <= 16 ? 61 : (mt_rand(0, 2) === 0 ? 2 : 0);
    $is_day = ($h >= 6 && $h <= 19) ? 1 : 0;
    $w = $db->prepare('INSERT OR REPLACE INTO weather (Date, Hour, Temp, ConditionCode, IsDay, WindSpeed, WindDirection) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $w->bindValue(1, $date, SQLITE3_TEXT);
    $w->bindValue(2, $h, SQLITE3_INTEGER);
    $w->bindValue(3, round($temp, 1), SQLITE3_FLOAT);
    $w->bindValue(4, $code, SQLITE3_INTEGER);
    $w->bindValue(5, $is_day, SQLITE3_INTEGER);
    $w->bindValue(6, mt_rand(0, 150) / 10.0, SQLITE3_FLOAT);
    $w->bindValue(7, mt_rand(0, 359), SQLITE3_INTEGER);
    $w->execute();
  }
}
$db->exec('COMMIT');

// Dev seasonal cache (normally written by get_seasonal_expected.py on the Pi):
// the location model's expected weekly occurrence per species. The rare
// arrival gets a near-zero score so region-rare badges/alerts can be tested.
$seasonal = ['lat' => 40.030, 'lon' => -75.020, 'version' => 1, 'data' => []];
foreach ($species as $sp) {
    $score = ($sp[1] === 'Sitta canadensis') ? 0.005 : 0.6;
    $seasonal['data'][$sp[1]] = array_fill(0, 48, $score);
}
file_put_contents(__DIR__ . '/../scripts/seasonal_cache.json', json_encode($seasonal));

echo "Seeded $total detections across " . ($days + 1) . " days, plus hourly weather and a seasonal cache.\n";
echo "Species: " . count($species) . " (includes a nocturnal owl, a rare new arrival, and a gone-quiet catbird for Insights testing).\n";
