#!/usr/bin/env bash
# Performs the recording from the specified RTSP stream or soundcard

# 1. 설정 파일이 있으면 불러오고, 없으면 기본값 설정 (에러 방지)
if [ -f /etc/birdnet/birdnet.conf ]; then
  source /etc/birdnet/birdnet.conf
fi

[ -z "$RECORDING_LENGTH" ] && RECORDING_LENGTH=15
[ -z "$RECS_DIR" ] && RECS_DIR="$HOME/BirdNET-Pi/Recorded"
[ -z "$CHANNELS" ] && CHANNELS=1

loop_ffmpeg(){
  while true;do
    if ! ffmpeg -hide_banner -loglevel $LOGGING_LEVEL -nostdin ${1} -i ${2} -vn -map a:0 -acodec pcm_s16le -ac 2 -ar 48000 -f segment -segment_format wav -segment_time ${RECORDING_LENGTH} -strftime 1 ${RECS_DIR}/StreamData/%F-birdnet-RTSP_${3}-%H:%M:%S.wav
    then
      sleep 1
    fi
  done
}

LOGGING_LEVEL="${LogLevel_BirdnetRecordingService}"
[ -z "$LOGGING_LEVEL" ] && LOGGING_LEVEL='error'
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ];then
  set -x
fi

# 저장 폴더가 없으면 자동 생성
[ -d "$RECS_DIR/StreamData" ] || mkdir -p "$RECS_DIR/StreamData"

if [ -n "${RTSP_STREAM}" ];then
  RTSP_STREAMS_EXPLODED_ARRAY=(${RTSP_STREAM//,/ })
  FFMPEG_VERSION=$(ffmpeg -version | head -n 1 | cut -d ' ' -f 3 | cut -d '.' -f 1)

  STREAM_COUNT=1
  for i in "${RTSP_STREAMS_EXPLODED_ARRAY[@]}"
  do
    if [[ "$i" =~ ^rtsps?:// ]]; then
      [ $FFMPEG_VERSION -lt 5 ] && PARAM=-stimeout || PARAM=-timeout
      TIMEOUT_PARAM="$PARAM 10000000"
    elif [[ "$i" =~ ^[a-z]+:// ]]; then
      TIMEOUT_PARAM="-rw_timeout 10000000"
    else
      TIMEOUT_PARAM=""
    fi
    loop_ffmpeg "${TIMEOUT_PARAM}" "${i}" "${STREAM_COUNT}" &
    ((STREAM_COUNT += 1))
  done
  wait
else
  # USB 마이크 (card 2: plughw:2,0) 무한 연속 녹음 파트
  echo "🎤 USB 마이크(plughw:2,0) 무한 실시간 녹음을 시작합니다..."
  
  while true; do
    arecord -D plughw:2,0 -f S16_LE -c1 -r48000 -t wav \
            --max-file-time ${RECORDING_LENGTH} \
            --use-strftime ${RECS_DIR}/StreamData/%F-birdnet-%H:%M:%S.wav
    sleep 1
  done
fi
