package news.meeha.correspondent;

import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.WebSettings;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;

/**
 * মিহা সংবাদদাতা — the web screens packed in the APK (Capacitor).
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WebView wv = getBridge().getWebView();
        // The login's refresh cookie is set by meeha.news for the app's
        // screens (served as correspondent.meeha.news): keep it.
        CookieManager cm = CookieManager.getInstance();
        cm.setAcceptCookie(true);
        cm.setAcceptThirdPartyCookies(wv, true);
        WebSettings s = wv.getSettings();
        // voice notes play from a tap that went through a few promises
        s.setMediaPlaybackRequiresUserGesture(false);
    }

    @Override
    public void onPause() {
        super.onPause();
        // write the cookie to disk so a swiped-away app stays logged in
        CookieManager.getInstance().flush();
    }
}
