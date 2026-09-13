import UIKit
import Capacitor
import CoreLocation

@UIApplicationMain
class AppDelegate: UIResponder, UIApplicationDelegate {

    var window: UIWindow?

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: deviceToken)
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
    }

    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        CrimeWatchLocationMonitor.shared.restore()
        return true
    }

    func applicationWillResignActive(_ application: UIApplication) {
        // Sent when the application is about to move from active to inactive state. This can occur for certain types of temporary interruptions (such as an incoming phone call or SMS message) or when the user quits the application and it begins the transition to the background state.
        // Use this method to pause ongoing tasks, disable timers, and invalidate graphics rendering callbacks. Games should use this method to pause the game.
    }

    func applicationDidEnterBackground(_ application: UIApplication) {
        // Use this method to release shared resources, save user data, invalidate timers, and store enough application state information to restore your application to its current state in case it is terminated later.
        // If your application supports background execution, this method is called instead of applicationWillTerminate: when the user quits.
    }

    func applicationWillEnterForeground(_ application: UIApplication) {
        // Called as part of the transition from the background to the active state; here you can undo many of the changes made on entering the background.
    }

    func applicationDidBecomeActive(_ application: UIApplication) {
        // Restart any tasks that were paused (or not yet started) while the application was inactive. If the application was previously in the background, optionally refresh the user interface.
    }

    func applicationWillTerminate(_ application: UIApplication) {
        // Called when the application is about to terminate. Save data if appropriate. See also applicationDidEnterBackground:.
    }

    func application(_ app: UIApplication, open url: URL, options: [UIApplication.OpenURLOptionsKey: Any] = [:]) -> Bool {
        // Called when the app was launched with a url. Feel free to add additional processing here,
        // but if you want the App API to support tracking app url opens, make sure to keep this call
        return ApplicationDelegateProxy.shared.application(app, open: url, options: options)
    }

    func application(_ application: UIApplication, continue userActivity: NSUserActivity, restorationHandler: @escaping ([UIUserActivityRestoring]?) -> Void) -> Bool {
        // Called when the app was launched with an activity, including Universal Links.
        // Feel free to add additional processing here, but if you want the App API to support
        // tracking app url opens, make sure to keep this call
        return ApplicationDelegateProxy.shared.application(application, continue: userActivity, restorationHandler: restorationHandler)
    }

}

// Significant-change monitoring avoids continuous GPS use. iOS controls its delivery timing.
final class CrimeWatchLocationMonitor: NSObject, CLLocationManagerDelegate {
    static let shared = CrimeWatchLocationMonitor()
    private let manager = CLLocationManager()
    private let store = UserDefaults.standard
    private var lastUpload: Date = .distantPast
    override init() { super.init(); manager.delegate = self }
    func restore() {
        if store.bool(forKey: "cw.background.enabled") && manager.authorizationStatus == .authorizedAlways {
            manager.startMonitoringSignificantLocationChanges()
        } else { manager.stopMonitoringSignificantLocationChanges() }
    }
    func configure(enabled: Bool, id: String?, secret: String?, requestPermission: Bool) {
        store.set(enabled, forKey: "cw.background.enabled")
        if enabled, let id = id, let secret = secret {
            store.set(id, forKey: "cw.background.id"); store.set(secret, forKey: "cw.background.secret")
            if requestPermission && manager.authorizationStatus == .authorizedWhenInUse { manager.requestAlwaysAuthorization() }
        } else {
            store.removeObject(forKey: "cw.background.id"); store.removeObject(forKey: "cw.background.secret")
        }
        restore()
    }
    var status: String {
        switch manager.authorizationStatus {
        case .authorizedAlways: return "always"
        case .authorizedWhenInUse: return "whenInUse"
        case .denied, .restricted: return "denied"
        default: return "prompt"
        }
    }
    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) { restore() }
    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard store.bool(forKey: "cw.background.enabled"), manager.authorizationStatus == .authorizedAlways,
              let point = locations.last, point.horizontalAccuracy >= 0, point.horizontalAccuracy <= 5000,
              abs(point.timestamp.timeIntervalSinceNow) < 300,
              Date().timeIntervalSince(lastUpload) > 60,
              let id = store.string(forKey: "cw.background.id"), let secret = store.string(forKey: "cw.background.secret") else { return }
        var request = URLRequest(url: URL(string: "https://crimewatch.live/alerts-api.php")!)
        request.httpMethod = "POST"; request.timeoutInterval = 12
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue(id, forHTTPHeaderField: "X-CrimeWatch-Device")
        request.setValue(secret, forHTTPHeaderField: "X-CrimeWatch-Secret")
        let center = [(point.coordinate.latitude * 1000).rounded() / 1000, (point.coordinate.longitude * 1000).rounded() / 1000]
        request.httpBody = try? JSONSerialization.data(withJSONObject: ["action":"location", "center":center, "locationUpdatedAt":Int(point.timestamp.timeIntervalSince1970)])
        var taskID: UIBackgroundTaskIdentifier = .invalid
        taskID = UIApplication.shared.beginBackgroundTask(withName: "CrimeWatch alert area") {
            if taskID != .invalid { UIApplication.shared.endBackgroundTask(taskID); taskID = .invalid }
        }
        lastUpload = Date()
        URLSession.shared.dataTask(with: request) { _, response, _ in
            DispatchQueue.main.async {
                if let status = (response as? HTTPURLResponse)?.statusCode, status == 401 || status == 409 {
                    self.configure(enabled: false, id: nil, secret: nil, requestPermission: false)
                }
                if taskID != .invalid { UIApplication.shared.endBackgroundTask(taskID); taskID = .invalid }
            }
        }.resume()
    }
}

@objc(CrimeWatchLocationPlugin)
public class CrimeWatchLocationPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "CrimeWatchLocationPlugin"
    public let jsName = "CrimeWatchLocation"
    public let pluginMethods: [CAPPluginMethod] = [CAPPluginMethod(name: "configure", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "status", returnType: CAPPluginReturnPromise)]
    @objc func configure(_ call: CAPPluginCall) {
        DispatchQueue.main.async {
            CrimeWatchLocationMonitor.shared.configure(enabled: call.getBool("enabled") ?? false, id: call.getString("id"), secret: call.getString("secret"), requestPermission: call.getBool("requestPermission") ?? false)
            call.resolve(["authorization":CrimeWatchLocationMonitor.shared.status])
        }
    }
    @objc func status(_ call: CAPPluginCall) { DispatchQueue.main.async { call.resolve(["authorization":CrimeWatchLocationMonitor.shared.status]) } }
}

class CrimeWatchViewController: CAPBridgeViewController {
    override func capacitorDidLoad() { bridge?.registerPluginInstance(CrimeWatchLocationPlugin()) }
}
