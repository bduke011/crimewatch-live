#!/bin/bash
set -euo pipefail
mkdir -p build
xcodebuild -project ios/App/App.xcodeproj -scheme App -configuration Debug -sdk iphonesimulator -destination 'generic/platform=iOS Simulator' -derivedDataPath build/simulator CODE_SIGNING_ALLOWED=YES CODE_SIGN_IDENTITY=- CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM=4C8AUSK829 CODE_SIGN_ENTITLEMENTS="$PWD/mobile/simulator.entitlements" build > build/simulator-compile.log 2>&1
DEVICE_ID=$(xcrun simctl list devices available -j | python3 -c 'import json,sys; d=json.load(sys.stdin); print(next(x["udid"] for group in d["devices"].values() for x in group if x["name"].startswith("iPhone")))')
xcrun simctl boot "$DEVICE_ID"
xcrun simctl bootstatus "$DEVICE_ID" -b
xcrun simctl install "$DEVICE_ID" build/simulator/Build/Products/Debug-iphonesimulator/App.app
xcrun simctl status_bar "$DEVICE_ID" override --time 9:41 --batteryState charged --batteryLevel 100
xcrun simctl launch "$DEVICE_ID" live.crimewatch.app --member-self-test
sleep 60
xcrun simctl spawn "$DEVICE_ID" log show --style compact --last 2m --predicate 'process == "App"' > build/simulator-runtime.log 2>&1
xcrun simctl io "$DEVICE_ID" screenshot build/iphone-launch.png
grep -q "CrimeWatch secure storage smoke PASS" build/simulator-runtime.log
xcrun simctl terminate "$DEVICE_ID" live.crimewatch.app
xcrun simctl shutdown "$DEVICE_ID"
