#!/bin/bash
set -euo pipefail
mkdir -p build
xcodebuild -project ios/App/App.xcodeproj -scheme App -configuration Debug -sdk iphonesimulator -destination 'generic/platform=iOS Simulator' -derivedDataPath build/simulator CODE_SIGNING_ALLOWED=NO build > build/simulator-compile.log 2>&1
DEVICE_ID=$(xcrun simctl list devices available -j | python3 -c 'import json,sys; d=json.load(sys.stdin); print(next(x["udid"] for group in d["devices"].values() for x in group if x["name"].startswith("iPhone")))')
xcrun simctl boot "$DEVICE_ID"
xcrun simctl bootstatus "$DEVICE_ID" -b
xcrun simctl install "$DEVICE_ID" build/simulator/Build/Products/Debug-iphonesimulator/App.app
xcrun simctl status_bar "$DEVICE_ID" override --time 9:41 --batteryState charged --batteryLevel 100
xcrun simctl launch --console-pty "$DEVICE_ID" live.crimewatch.app > build/simulator-runtime.log 2>&1 &
APP_LOG_PID=$!
sleep 40
xcrun simctl io "$DEVICE_ID" screenshot build/iphone-launch.png
xcrun simctl terminate "$DEVICE_ID" live.crimewatch.app
xcrun simctl shutdown "$DEVICE_ID"
