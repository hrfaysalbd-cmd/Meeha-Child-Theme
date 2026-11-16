package news.meeha.correspondent;

import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;

import androidx.core.content.FileProvider;
import androidx.core.content.pm.PackageInfoCompat;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * r35 — the app updates itself: the new APK is downloaded inside the app
 * (no outside browser) and handed to Android's own installer. Android always
 * shows its "Install / Update" screen — an app cannot install silently — and
 * the first time it asks once to allow installs from this app.
 *
 * Only https links on meeha.news are accepted, and the file must be this
 * same app (package name) with a newer build number.
 */
@CapacitorPlugin(name = "ApkInstaller")
public class ApkInstallerPlugin extends Plugin {

    private final ExecutorService pool = Executors.newSingleThreadExecutor();
    private volatile boolean busy = false;

    private File updatesDir() {
        File d = new File(getContext().getCacheDir(), "updates");
        if (!d.exists()) d.mkdirs();
        return d;
    }

    private boolean allowed() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return true;
        return getContext().getPackageManager().canRequestPackageInstalls();
    }

    @PluginMethod
    public void canInstall(PluginCall call) {
        JSObject r = new JSObject();
        r.put("allowed", allowed());
        call.resolve(r);
    }

    @PluginMethod
    public void openSettings(PluginCall call) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent i = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getContext().getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(i);
        }
        call.resolve();
    }

    @PluginMethod
    public void download(PluginCall call) {
        final String url = call.getString("url", "");
        final long minCode = call.getLong("versionCode", 0L);
        Uri u = Uri.parse(url);
        String host = u.getHost() == null ? "" : u.getHost();
        if (!"https".equals(u.getScheme()) || !(host.equals("meeha.news") || host.endsWith(".meeha.news"))) {
            call.reject("এই লিংক থেকে আপডেট নেওয়া যাবে না।");
            return;
        }
        if (busy) {
            call.reject("আপডেট আগে থেকেই নামছে।");
            return;
        }
        busy = true;
        pool.execute(() -> {
            File tmp = new File(updatesDir(), "meeha-update.apk.part");
            File out = new File(updatesDir(), "meeha-update.apk");
            HttpURLConnection c = null;
            try {
                c = (HttpURLConnection) new URL(url).openConnection();
                c.setConnectTimeout(20000);
                c.setReadTimeout(60000);
                c.setInstanceFollowRedirects(true);
                c.setRequestProperty("Cache-Control", "no-cache");
                int code = c.getResponseCode();
                if (code != 200) throw new Exception("সার্ভার উত্তর দিল " + code);
                long total = c.getContentLengthLong();
                long loaded = 0;
                long lastSent = 0;
                byte[] buf = new byte[65536];
                try (InputStream in = c.getInputStream(); OutputStream os = new FileOutputStream(tmp)) {
                    int n;
                    while ((n = in.read(buf)) > 0) {
                        os.write(buf, 0, n);
                        loaded += n;
                        if (loaded - lastSent >= 262144 || loaded == total) {
                            lastSent = loaded;
                            JSObject p = new JSObject();
                            p.put("loaded", loaded);
                            p.put("total", total);
                            notifyListeners("progress", p);
                        }
                    }
                }
                if (total > 0 && loaded != total) throw new Exception("ফাইল পুরো নামেনি");
                if (out.exists()) out.delete();
                if (!tmp.renameTo(out)) throw new Exception("ফাইল রাখা যায়নি");

                Context ctx = getContext();
                PackageManager pm = ctx.getPackageManager();
                PackageInfo pi = pm.getPackageArchiveInfo(out.getAbsolutePath(), 0);
                if (pi == null) throw new Exception("নামানো ফাইলটি ঠিক APK নয়");
                if (!ctx.getPackageName().equals(pi.packageName)) throw new Exception("ফাইলটি এই অ্যাপের নয়");
                long got = PackageInfoCompat.getLongVersionCode(pi);
                long mine = PackageInfoCompat.getLongVersionCode(pm.getPackageInfo(ctx.getPackageName(), 0));
                if (got <= mine || (minCode > 0 && got < minCode)) throw new Exception("ফাইলটি নতুন ভার্সন নয়");

                JSObject r = new JSObject();
                r.put("path", out.getAbsolutePath());
                r.put("versionCode", got);
                call.resolve(r);
            } catch (Exception e) {
                tmp.delete();
                call.reject(e.getMessage() == null ? "ডাউনলোড হয়নি" : e.getMessage());
            } finally {
                if (c != null) c.disconnect();
                busy = false;
            }
        });
    }

    @PluginMethod
    public void install(PluginCall call) {
        File f = new File(updatesDir(), "meeha-update.apk");
        if (!f.exists()) {
            call.reject("আগে আপডেটটি নামান।");
            return;
        }
        JSObject r = new JSObject();
        if (!allowed()) {
            r.put("needPermission", true);
            call.resolve(r);
            return;
        }
        Context ctx = getContext();
        Uri uri = FileProvider.getUriForFile(ctx, ctx.getPackageName() + ".fileprovider", f);
        Intent i = new Intent(Intent.ACTION_VIEW);
        i.setDataAndType(uri, "application/vnd.android.package-archive");
        i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
        ctx.startActivity(i);
        r.put("started", true);
        call.resolve(r);
    }
}
