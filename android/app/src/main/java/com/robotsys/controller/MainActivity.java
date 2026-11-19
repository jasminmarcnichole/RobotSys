package com.robotsys.controller;

import android.app.Activity;
import android.content.SharedPreferences;
import android.content.pm.ActivityInfo;
import android.content.res.Configuration;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.HapticFeedbackConstants;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private static final String PREFS_NAME = "robotsys_native_prefs";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_DIRECT_URL = "direct_url";
    private static final String KEY_TOKEN = "sanctum_token";
    private static final String KEY_USER_NAME = "user_name";
    private static final String KEY_USER_EMAIL = "user_email";
    private static final String KEY_LAST_EMAIL = "last_email";
    private static final String KEY_ROBOT_ID = "robot_id";

    private static final String DEFAULT_DIRECT_URL = "http://192.168.4.1";
    private static final long POLL_INTERVAL_MS = 5000L;

    private final ExecutorService executor = Executors.newCachedThreadPool();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private SharedPreferences prefs;

    private LinearLayout topHeaderBar;
    private TextView headerSubtitle;
    private Button headerRotateBtn;
    private Button headerSyncBtn;
    private TextView statusBanner;
    private Runnable hideBannerRunnable;

    private View loginScreen;
    private EditText loginServerInput;
    private EditText loginEmailInput;
    private EditText loginPasswordInput;
    private Button togglePasswordBtn;
    private boolean passwordVisible = false;
    private TextView emailErrorText;
    private TextView passwordErrorText;
    private Button loginSubmitBtn;

    private LinearLayout mainShell;
    private View tabControllerView;
    private View tabHistoryView;
    private View tabSettingsView;
    private Button navControllerBtn;
    private Button navHistoryBtn;
    private Button navSettingsBtn;
    private LinearLayout bottomNavBar;

    // Controller deck responsive containers
    private LinearLayout controllerDecksContainer;
    private LinearLayout wheelDeckCard;
    private LinearLayout armDeckCard;
    private Button modeAutoBtn;
    private Button modeManualBtn;
    private boolean isAutoMode = false;

    private TextView telemetryRobotText;
    private TextView telemetryStatusPill;
    private TextView telemetryBatteryText;
    private View batteryBarFill;
    private TextView telemetryPlasticBinText;
    private TextView telemetryGeneralBinText;
    private TextView telemetryUpdatedText;
    private TextView directStatusText;

    private LinearLayout historyCardsContainer;
    private Button historyLoadMoreBtn;
    private int currentHistoryPage = 1;
    private int lastHistoryPage = 1;

    private TextView accountNameText;
    private TextView accountEmailText;
    private EditText settingsApiUrlInput;
    private EditText settingsDirectUrlInput;
    private EditText settingsRobotIdInput;
    private TextView directTestResultText;

    private boolean isLandscapeLocked = false;
    private int activeTabIndex = 0;

    private final Runnable autoPollRunnable = new Runnable() {
        @Override
        public void run() {
            if (isAuthenticated()) {
                fetchStatus(false);
                mainHandler.postDelayed(this, POLL_INTERVAL_MS);
            }
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);

        getWindow().setStatusBarColor(Color.parseColor("#042F24"));
        getWindow().setNavigationBarColor(Color.parseColor("#0B1412"));

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setFitsSystemWindows(true);
        root.setBackgroundColor(Color.parseColor("#0B1412"));

        topHeaderBar = buildTopHeader();
        root.addView(topHeaderBar, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        statusBanner = new TextView(this);
        statusBanner.setPadding(dp(16), dp(9), dp(16), dp(9));
        statusBanner.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        statusBanner.setVisibility(View.GONE);
        statusBanner.setOnClickListener(v -> statusBanner.setVisibility(View.GONE));
        root.addView(statusBanner, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        FrameLayout bodyFrame = new FrameLayout(this);
        loginScreen = buildLoginScreen();
        mainShell = buildAuthenticatedShell();

        bodyFrame.addView(loginScreen, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        bodyFrame.addView(mainShell, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        root.addView(bodyFrame, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));

        setContentView(root);
        applyOrientationLayout(isLandscape());
        refreshAuthState();
    }

    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);
        applyOrientationLayout(newConfig.orientation == Configuration.ORIENTATION_LANDSCAPE);
    }

    private boolean isLandscape() {
        return getResources().getConfiguration().orientation == Configuration.ORIENTATION_LANDSCAPE;
    }

    private void applyOrientationLayout(boolean landscape) {
        if (topHeaderBar != null) {
            topHeaderBar.setPadding(dp(14), dp(landscape ? 8 : 12), dp(14), dp(landscape ? 8 : 12));
        }
        if (headerRotateBtn != null) {
            headerRotateBtn.setText(landscape ? "Portrait" : "Landscape");
        }
        if (bottomNavBar != null) {
            bottomNavBar.setPadding(dp(12), dp(landscape ? 5 : 8), dp(12), dp(landscape ? 6 : 10));
        }
        if (controllerDecksContainer != null && wheelDeckCard != null && armDeckCard != null) {
            controllerDecksContainer.setOrientation(landscape ? LinearLayout.HORIZONTAL : LinearLayout.VERTICAL);

            LinearLayout.LayoutParams leftLp;
            LinearLayout.LayoutParams rightLp;
            if (landscape) {
                leftLp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
                leftLp.rightMargin = dp(6);
                rightLp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
                rightLp.leftMargin = dp(6);
            } else {
                leftLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                rightLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                rightLp.topMargin = dp(10);
            }
            wheelDeckCard.setLayoutParams(leftLp);
            armDeckCard.setLayoutParams(rightLp);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (isAuthenticated()) {
            startAutoPolling();
        }
    }

    @Override
    protected void onPause() {
        super.onPause();
        stopAutoPolling();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        stopAutoPolling();
        if (hideBannerRunnable != null) {
            mainHandler.removeCallbacks(hideBannerRunnable);
        }
        executor.shutdownNow();
    }

    private void startAutoPolling() {
        stopAutoPolling();
        mainHandler.postDelayed(autoPollRunnable, POLL_INTERVAL_MS);
    }

    private void stopAutoPolling() {
        mainHandler.removeCallbacks(autoPollRunnable);
    }

    private LinearLayout buildTopHeader() {
        LinearLayout bar = new LinearLayout(this);
        bar.setOrientation(LinearLayout.HORIZONTAL);
        bar.setGravity(Gravity.CENTER_VERTICAL);
        bar.setPadding(dp(14), dp(12), dp(14), dp(12));
        bar.setBackgroundColor(Color.parseColor("#042F24"));

        ImageView badge = new ImageView(this);
        badge.setImageResource(R.drawable.ic_robotsys_logo);
        badge.setScaleType(ImageView.ScaleType.FIT_CENTER);
        badge.setContentDescription("RobotSys logo");
        LinearLayout.LayoutParams badgeLp = new LinearLayout.LayoutParams(dp(36), dp(36));
        badgeLp.rightMargin = dp(10);
        bar.addView(badge, badgeLp);

        LinearLayout titles = new LinearLayout(this);
        titles.setOrientation(LinearLayout.VERTICAL);

        TextView title = new TextView(this);
        title.setText("RobotSys Controller");
        title.setTextColor(Color.WHITE);
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        titles.addView(title);

        headerSubtitle = new TextView(this);
        headerSubtitle.setTextColor(Color.parseColor("#A7F3D0"));
        headerSubtitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        titles.addView(headerSubtitle);

        bar.addView(titles, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        headerRotateBtn = createButton("Landscape", "#064E3B", "#D1FAE5", "#10B981", dp(16), 11);
        headerRotateBtn.setPadding(dp(12), 0, dp(12), 0);
        headerRotateBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            if (isLandscape()) {
                isLandscapeLocked = false;
                setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_PORTRAIT);
            } else {
                isLandscapeLocked = true;
                setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE);
            }
        });
        LinearLayout.LayoutParams rotLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(32));
        rotLp.rightMargin = dp(8);
        bar.addView(headerRotateBtn, rotLp);

        headerSyncBtn = createButton("Sync", "#065F46", "#FFFFFF", "#10B981", dp(16), 11);
        headerSyncBtn.setPadding(dp(12), 0, dp(12), 0);
        headerSyncBtn.setOnClickListener(v -> {
            if (isAuthenticated()) {
                fetchStatus(true);
                fetchCommandHistory(1);
            }
        });
        bar.addView(headerSyncBtn, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(32)));

        return bar;
    }

    private View buildLoginScreen() {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(Color.parseColor("#F4F6F4"));

        LinearLayout container = new LinearLayout(this);
        container.setOrientation(LinearLayout.VERTICAL);
        container.setGravity(Gravity.CENTER_HORIZONTAL);
        container.setPadding(dp(20), dp(22), dp(20), dp(28));

        LinearLayout card = createLightCard();

        ImageView loginLogo = new ImageView(this);
        loginLogo.setImageResource(R.drawable.ic_robotsys_logo);
        loginLogo.setScaleType(ImageView.ScaleType.FIT_CENTER);
        loginLogo.setContentDescription("RobotSys logo");
        LinearLayout.LayoutParams logoLp = new LinearLayout.LayoutParams(dp(54), dp(54));
        logoLp.bottomMargin = dp(12);
        card.addView(loginLogo, logoLp);

        TextView eyebrow = new TextView(this);
        eyebrow.setText("SANCTUM SECURE SIGN-IN");
        eyebrow.setTextColor(Color.parseColor("#047857"));
        eyebrow.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        eyebrow.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(eyebrow);

        TextView heading = new TextView(this);
        heading.setText("Connect to RobotSys");
        heading.setTextColor(Color.parseColor("#18181B"));
        heading.setTextSize(TypedValue.COMPLEX_UNIT_SP, 21);
        heading.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(heading, topMarginLp(dp(4)));

        TextView sub = new TextView(this);
        sub.setText("Sign in with your administrator account to sync telemetry and launch the dual-hand controller.");
        sub.setTextColor(Color.parseColor("#52525B"));
        sub.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        card.addView(sub, topMarginLp(dp(6)));

        card.addView(createFieldLabel("Laravel Server URL"), topMarginLp(dp(16)));
        loginServerInput = createInput(
                prefs.getString(KEY_BASE_URL, ""),
                "https://your-app.up.railway.app or http://192.168.x.x:8000",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI
        );
        card.addView(loginServerInput, topMarginLp(dp(6)));

        TextView urlHint = new TextView(this);
        urlHint.setText("Use your deployed HTTPS domain, or your PC's Wi-Fi IP (e.g. http://192.168.x.x:8000).");
        urlHint.setTextColor(Color.parseColor("#71717A"));
        urlHint.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        card.addView(urlHint, topMarginLp(dp(4)));

        card.addView(createFieldLabel("Administrator Email"), topMarginLp(dp(12)));
        loginEmailInput = createInput(
                prefs.getString(KEY_LAST_EMAIL, ""),
                "admin@example.com",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
        );
        card.addView(loginEmailInput, topMarginLp(dp(6)));

        emailErrorText = createErrorText();
        card.addView(emailErrorText, topMarginLp(dp(4)));

        LinearLayout passLabelRow = new LinearLayout(this);
        passLabelRow.setOrientation(LinearLayout.HORIZONTAL);
        passLabelRow.setGravity(Gravity.CENTER_VERTICAL);
        passLabelRow.addView(createFieldLabel("Password"),
                new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        togglePasswordBtn = new Button(this);
        togglePasswordBtn.setText("Show");
        togglePasswordBtn.setAllCaps(false);
        togglePasswordBtn.setTextColor(Color.parseColor("#047857"));
        togglePasswordBtn.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        togglePasswordBtn.setTypeface(Typeface.DEFAULT_BOLD);
        togglePasswordBtn.setBackgroundColor(Color.TRANSPARENT);
        togglePasswordBtn.setPadding(dp(8), dp(2), dp(4), dp(2));
        togglePasswordBtn.setMinHeight(0);
        togglePasswordBtn.setMinimumHeight(0);
        togglePasswordBtn.setOnClickListener(v -> togglePasswordVisibility());
        passLabelRow.addView(togglePasswordBtn, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        card.addView(passLabelRow, topMarginLp(dp(12)));

        loginPasswordInput = createInput(
                "",
                "Enter your password",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD
        );
        card.addView(loginPasswordInput, topMarginLp(dp(6)));

        passwordErrorText = createErrorText();
        card.addView(passwordErrorText, topMarginLp(dp(4)));

        loginSubmitBtn = createButton("Sign In & Launch Controller", "#047857", "#FFFFFF", "#065F46", dp(14), 14);
        loginSubmitBtn.setOnClickListener(v -> performLogin());
        LinearLayout.LayoutParams btnLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(48));
        btnLp.topMargin = dp(18);
        card.addView(loginSubmitBtn, btnLp);

        container.addView(card, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        scroll.addView(container);
        return scroll;
    }

    private void togglePasswordVisibility() {
        passwordVisible = !passwordVisible;
        int selection = loginPasswordInput.getSelectionEnd();
        loginPasswordInput.setInputType(passwordVisible
                ? InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD
                : InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        loginPasswordInput.setTypeface(Typeface.DEFAULT);
        if (selection >= 0) {
            loginPasswordInput.setSelection(selection);
        }
        togglePasswordBtn.setText(passwordVisible ? "Hide" : "Show");
    }

    private LinearLayout buildAuthenticatedShell() {
        LinearLayout shell = new LinearLayout(this);
        shell.setOrientation(LinearLayout.VERTICAL);

        FrameLayout pages = new FrameLayout(this);
        tabControllerView = buildControllerTab();
        tabHistoryView = buildHistoryTab();
        tabSettingsView = buildSettingsTab();

        pages.addView(tabControllerView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        pages.addView(tabHistoryView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        pages.addView(tabSettingsView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        shell.addView(pages, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));

        View divider = new View(this);
        divider.setBackgroundColor(Color.parseColor("#1E332C"));
        shell.addView(divider, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(1)));

        bottomNavBar = new LinearLayout(this);
        bottomNavBar.setOrientation(LinearLayout.HORIZONTAL);
        bottomNavBar.setPadding(dp(12), dp(8), dp(12), dp(10));
        bottomNavBar.setBackgroundColor(Color.parseColor("#0B1412"));

        navControllerBtn = createNavTabButton("Controller Pad", () -> switchTab(0));
        navHistoryBtn = createNavTabButton("Commands", () -> {
            switchTab(1);
            fetchCommandHistory(1);
        });
        navSettingsBtn = createNavTabButton("Settings", () -> switchTab(2));

        bottomNavBar.addView(navControllerBtn, new LinearLayout.LayoutParams(0, dp(40), 1f));
        LinearLayout.LayoutParams midLp = new LinearLayout.LayoutParams(0, dp(40), 1f);
        midLp.leftMargin = dp(8);
        midLp.rightMargin = dp(8);
        bottomNavBar.addView(navHistoryBtn, midLp);
        bottomNavBar.addView(navSettingsBtn, new LinearLayout.LayoutParams(0, dp(40), 1f));

        shell.addView(bottomNavBar, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        switchTab(0);
        return shell;
    }

    private View buildControllerTab() {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(Color.parseColor("#0B1412"));

        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(dp(12), dp(10), dp(12), dp(14));

        // 1. Compact Telemetry HUD Bar
        LinearLayout hudCard = createDarkCard("#111E1B", "#1E3A32");
        hudCard.setPadding(dp(12), dp(10), dp(12), dp(10));

        LinearLayout hudTop = new LinearLayout(this);
        hudTop.setOrientation(LinearLayout.HORIZONTAL);
        hudTop.setGravity(Gravity.CENTER_VERTICAL);

        telemetryRobotText = new TextView(this);
        telemetryRobotText.setText(getRobotId());
        telemetryRobotText.setTextColor(Color.WHITE);
        telemetryRobotText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        telemetryRobotText.setTypeface(Typeface.DEFAULT_BOLD);
        hudTop.addView(telemetryRobotText, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        directStatusText = new TextView(this);
        directStatusText.setText("ESP32 Ready");
        directStatusText.setTextColor(Color.parseColor("#6EE7B7"));
        directStatusText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        directStatusText.setPadding(0, 0, dp(10), 0);
        hudTop.addView(directStatusText);

        telemetryStatusPill = new TextView(this);
        telemetryStatusPill.setText("Awaiting Data");
        telemetryStatusPill.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        telemetryStatusPill.setTypeface(Typeface.DEFAULT_BOLD);
        telemetryStatusPill.setPadding(dp(9), dp(3), dp(9), dp(3));
        styleStatusPill(telemetryStatusPill, "neutral");
        hudTop.addView(telemetryStatusPill);
        hudCard.addView(hudTop);

        LinearLayout hudMetrics = new LinearLayout(this);
        hudMetrics.setOrientation(LinearLayout.HORIZONTAL);
        hudMetrics.setGravity(Gravity.CENTER_VERTICAL);

        // Battery compact block
        LinearLayout batWrap = new LinearLayout(this);
        batWrap.setOrientation(LinearLayout.VERTICAL);
        LinearLayout batLabelRow = new LinearLayout(this);
        batLabelRow.setOrientation(LinearLayout.HORIZONTAL);
        TextView batTitle = new TextView(this);
        batTitle.setText("BAT ");
        batTitle.setTextColor(Color.parseColor("#9CA3AF"));
        batTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 10);
        batTitle.setTypeface(Typeface.DEFAULT_BOLD);
        batLabelRow.addView(batTitle);

        telemetryBatteryText = new TextView(this);
        telemetryBatteryText.setText("--%");
        telemetryBatteryText.setTextColor(Color.WHITE);
        telemetryBatteryText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        telemetryBatteryText.setTypeface(Typeface.DEFAULT_BOLD);
        batLabelRow.addView(telemetryBatteryText);
        batWrap.addView(batLabelRow);

        FrameLayout batteryTrack = new FrameLayout(this);
        batteryTrack.setBackground(roundedBox("#1F2937", "#374151", dp(99)));
        batteryBarFill = new View(this);
        batteryBarFill.setBackground(roundedBox("#10B981", "#10B981", dp(99)));
        batteryTrack.addView(batteryBarFill, new FrameLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT));
        LinearLayout.LayoutParams trackLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(6));
        trackLp.topMargin = dp(4);
        batWrap.addView(batteryTrack, trackLp);

        LinearLayout.LayoutParams batWrapLp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1.1f);
        batWrapLp.rightMargin = dp(8);
        hudMetrics.addView(batWrap, batWrapLp);

        telemetryPlasticBinText = createDarkMetricChip("Plastic: --");
        LinearLayout.LayoutParams pChipLp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        pChipLp.rightMargin = dp(6);
        hudMetrics.addView(telemetryPlasticBinText, pChipLp);

        telemetryGeneralBinText = createDarkMetricChip("General: --");
        hudMetrics.addView(telemetryGeneralBinText, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        hudCard.addView(hudMetrics, topMarginLp(dp(8)));

        telemetryUpdatedText = new TextView(this);
        telemetryUpdatedText.setText("Telemetry: checking...");
        telemetryUpdatedText.setTextColor(Color.parseColor("#9CA3AF"));
        telemetryUpdatedText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 10);
        hudCard.addView(telemetryUpdatedText, topMarginLp(dp(5)));

        col.addView(hudCard);

        // 2. Dual-Hand Controller Decks Container (Horizontal in Landscape, Vertical in Portrait)
        controllerDecksContainer = new LinearLayout(this);
        controllerDecksContainer.setOrientation(isLandscape() ? LinearLayout.HORIZONTAL : LinearLayout.VERTICAL);

        wheelDeckCard = buildWheelMovementDeck();
        armDeckCard = buildRoboticArmDeck();

        controllerDecksContainer.addView(wheelDeckCard);
        controllerDecksContainer.addView(armDeckCard);

        col.addView(controllerDecksContainer, topMarginLp(dp(10)));
        scroll.addView(col);
        return scroll;
    }

    private LinearLayout buildWheelMovementDeck() {
        LinearLayout card = createDarkCard("#111E1B", "#1E3A32");

        LinearLayout head = new LinearLayout(this);
        head.setOrientation(LinearLayout.HORIZONTAL);
        head.setGravity(Gravity.CENTER_VERTICAL);

        TextView title = new TextView(this);
        title.setText("WHEELS & DRIVE MODE");
        title.setTextColor(Color.parseColor("#A7F3D0"));
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        head.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        TextView hint = new TextView(this);
        hint.setText("Hold to drive");
        hint.setTextColor(Color.parseColor("#9CA3AF"));
        hint.setTextSize(TypedValue.COMPLEX_UNIT_SP, 10);
        head.addView(hint);
        card.addView(head);

        // Segmented AUTO / MANUAL Mode Switch
        LinearLayout modeTrack = new LinearLayout(this);
        modeTrack.setOrientation(LinearLayout.HORIZONTAL);
        modeTrack.setPadding(dp(4), dp(4), dp(4), dp(4));
        modeTrack.setBackground(roundedBox("#09110F", "#1F352E", dp(14)));

        modeAutoBtn = createButton("AUTO MODE", "#111E1B", "#9CA3AF", "#1F352E", dp(10), 12);
        modeManualBtn = createButton("MANUAL MODE", "#047857", "#FFFFFF", "#10B981", dp(10), 12);

        modeAutoBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            setDriveMode(true);
            sendDirectCommand("A");
            sendApiCommand("START");
        });
        modeManualBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            setDriveMode(false);
            sendDirectCommand("M");
            sendApiCommand("RESET");
        });

        LinearLayout.LayoutParams halfL = new LinearLayout.LayoutParams(0, dp(40), 1f);
        halfL.rightMargin = dp(3);
        LinearLayout.LayoutParams halfR = new LinearLayout.LayoutParams(0, dp(40), 1f);
        halfR.leftMargin = dp(3);
        modeTrack.addView(modeAutoBtn, halfL);
        modeTrack.addView(modeManualBtn, halfR);
        card.addView(modeTrack, topMarginLp(dp(10)));

        // Recessed Gamepad D-Pad
        LinearLayout padBox = new LinearLayout(this);
        padBox.setOrientation(LinearLayout.VERTICAL);
        padBox.setPadding(dp(10), dp(10), dp(10), dp(10));
        padBox.setBackground(roundedBox("#09110F", "#1F352E", dp(18)));

        padBox.addView(buildDpadRow(null, "▲\nFWD", "F", false, null));
        padBox.addView(buildDpadRow("◀\nLEFT", "■\nSTOP", "S", true, "▶\nRIGHT"), topMarginLp(dp(8)));
        padBox.addView(buildDpadRow(null, "▼\nBACK", "B", false, null), topMarginLp(dp(8)));
        card.addView(padBox, topMarginLp(dp(10)));

        setDriveMode(false);
        return card;
    }

    private void setDriveMode(boolean auto) {
        isAutoMode = auto;
        if (modeAutoBtn != null && modeManualBtn != null) {
            if (auto) {
                modeAutoBtn.setBackground(roundedBox("#047857", "#10B981", dp(10)));
                modeAutoBtn.setTextColor(Color.WHITE);
                modeManualBtn.setBackground(roundedBox("#0B1412", "#1F352E", dp(10)));
                modeManualBtn.setTextColor(Color.parseColor("#9CA3AF"));
            } else {
                modeManualBtn.setBackground(roundedBox("#047857", "#10B981", dp(10)));
                modeManualBtn.setTextColor(Color.WHITE);
                modeAutoBtn.setBackground(roundedBox("#0B1412", "#1F352E", dp(10)));
                modeAutoBtn.setTextColor(Color.parseColor("#9CA3AF"));
            }
        }
    }

    private LinearLayout buildRoboticArmDeck() {
        LinearLayout card = createDarkCard("#111E1B", "#1E3A32");

        LinearLayout head = new LinearLayout(this);
        head.setOrientation(LinearLayout.HORIZONTAL);
        head.setGravity(Gravity.CENTER_VERTICAL);

        TextView title = new TextView(this);
        title.setText("6-DOF ROBOTIC ARM");
        title.setTextColor(Color.parseColor("#A7F3D0"));
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        head.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        TextView hint = new TextView(this);
        hint.setText("Gripper & Poses");
        hint.setTextColor(Color.parseColor("#9CA3AF"));
        hint.setTextSize(TypedValue.COMPLEX_UNIT_SP, 10);
        head.addView(hint);
        card.addView(head);

        LinearLayout.LayoutParams halfL = new LinearLayout.LayoutParams(0, dp(48), 1f);
        halfL.rightMargin = dp(5);
        LinearLayout.LayoutParams halfR = new LinearLayout.LayoutParams(0, dp(48), 1f);
        halfR.leftMargin = dp(5);

        // 1. Gripper Open / Close
        LinearLayout gripRow = new LinearLayout(this);
        gripRow.setOrientation(LinearLayout.HORIZONTAL);
        Button openBtn = createGamepadActionButton("OPEN GRIP\n(Ch 5)", "#182B26", "#FFFFFF", "#2D5247");
        openBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("O");
        });
        Button closeBtn = createGamepadActionButton("CLOSE GRIP\n(Ch 5)", "#182B26", "#FFFFFF", "#2D5247");
        closeBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("C");
        });
        gripRow.addView(openBtn, halfL);
        gripRow.addView(closeBtn, halfR);
        card.addView(gripRow, topMarginLp(dp(10)));

        // 2. Smart Grab -> Bin Sorting
        LinearLayout sortRow = new LinearLayout(this);
        sortRow.setOrientation(LinearLayout.HORIZONTAL);
        Button plasticBtn = createGamepadActionButton("GRAB → PLASTIC\nBin (P)", "#064E3B", "#D1FAE5", "#10B981");
        plasticBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("P");
        });
        Button generalBtn = createGamepadActionButton("GRAB → GENERAL\nBin (G)", "#1E293B", "#E2E8F0", "#475569");
        generalBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("G");
        });
        sortRow.addView(plasticBtn, halfL);
        sortRow.addView(generalBtn, halfR);
        card.addView(sortRow, topMarginLp(dp(8)));

        // 3. Quick Arm Reach Poses
        LinearLayout poseRow = new LinearLayout(this);
        poseRow.setOrientation(LinearLayout.HORIZONTAL);
        Button frontPoseBtn = createGamepadActionButton("FRONT REACH", "#182B26", "#A7F3D0", "#2D5247");
        frontPoseBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectPath("/arm/preview?pose=front", "Front Reach");
        });
        Button rearPoseBtn = createGamepadActionButton("REAR DROP", "#182B26", "#A7F3D0", "#2D5247");
        rearPoseBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectPath("/arm/preview?pose=rear", "Rear Drop");
        });
        LinearLayout.LayoutParams poseL = new LinearLayout.LayoutParams(0, dp(44), 1f);
        poseL.rightMargin = dp(5);
        LinearLayout.LayoutParams poseR = new LinearLayout.LayoutParams(0, dp(44), 1f);
        poseR.leftMargin = dp(5);
        poseRow.addView(frontPoseBtn, poseL);
        poseRow.addView(rearPoseBtn, poseR);
        card.addView(poseRow, topMarginLp(dp(8)));

        // 4. Base Swivel & Home Row
        LinearLayout baseRow = new LinearLayout(this);
        baseRow.setOrientation(LinearLayout.HORIZONTAL);
        Button baseLeftBtn = createGamepadActionButton("⟲ BASE\n+5°", "#0F1D19", "#E5E7EB", "#2D5247");
        baseLeftBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("[");
        });
        Button homeArmBtn = createGamepadActionButton("HOME\nARM (H)", "#047857", "#FFFFFF", "#10B981");
        homeArmBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("H");
        });
        Button baseRightBtn = createGamepadActionButton("BASE ⟳\n-5°", "#0F1D19", "#E5E7EB", "#2D5247");
        baseRightBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("]");
        });

        LinearLayout.LayoutParams t1 = new LinearLayout.LayoutParams(0, dp(48), 1f);
        LinearLayout.LayoutParams t2 = new LinearLayout.LayoutParams(0, dp(48), 1f);
        t2.leftMargin = dp(6);
        t2.rightMargin = dp(6);
        LinearLayout.LayoutParams t3 = new LinearLayout.LayoutParams(0, dp(48), 1f);
        baseRow.addView(baseLeftBtn, t1);
        baseRow.addView(homeArmBtn, t2);
        baseRow.addView(baseRightBtn, t3);
        card.addView(baseRow, topMarginLp(dp(8)));

        return card;
    }

    private LinearLayout buildDpadRow(String leftLabel, String centerLabel, String centerCmd, boolean centerDanger, String rightLabel) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);

        LinearLayout.LayoutParams c1 = new LinearLayout.LayoutParams(0, dp(56), 1f);
        LinearLayout.LayoutParams c2 = new LinearLayout.LayoutParams(0, dp(56), 1f);
        c2.leftMargin = dp(8);
        c2.rightMargin = dp(8);
        LinearLayout.LayoutParams c3 = new LinearLayout.LayoutParams(0, dp(56), 1f);

        if (leftLabel != null) {
            row.addView(createHoldDriveButton(leftLabel, "L"), c1);
        } else {
            row.addView(new View(this), c1);
        }

        if (centerDanger) {
            Button midBtn = createButton(centerLabel, "#B91C1C", "#FFFFFF", "#EF4444", dp(14), 11);
            midBtn.setOnClickListener(v -> {
                v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
                setDriveMode(false);
                sendDirectCommand("S");
                sendApiCommand("STOP");
            });
            row.addView(midBtn, c2);
        } else {
            row.addView(createHoldDriveButton(centerLabel, centerCmd), c2);
        }

        if (rightLabel != null) {
            row.addView(createHoldDriveButton(rightLabel, "R"), c3);
        } else {
            row.addView(new View(this), c3);
        }

        return row;
    }

    private Button createHoldDriveButton(String label, String cmd) {
        Button btn = createButton(label, "#182B26", "#FFFFFF", "#2D5247", dp(14), 11);
        btn.setOnTouchListener((v, event) -> {
            int action = event.getActionMasked();
            if (action == MotionEvent.ACTION_DOWN) {
                v.getParent().requestDisallowInterceptTouchEvent(true);
                v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
                v.setBackground(roundedBox("#047857", "#34D399", dp(14)));
                btn.setTextColor(Color.WHITE);
                if (isAutoMode) {
                    setDriveMode(false);
                }
                sendDirectCommand(cmd);
                return true;
            } else if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
                v.getParent().requestDisallowInterceptTouchEvent(false);
                v.setBackground(roundedBox("#182B26", "#2D5247", dp(14)));
                btn.setTextColor(Color.WHITE);
                sendDirectCommand("S");
                return true;
            }
            return false;
        });
        return btn;
    }

    private Button createGamepadActionButton(String label, String fillHex, String textHex, String strokeHex) {
        Button btn = createButton(label, fillHex, textHex, strokeHex, dp(12), 11);
        btn.setPadding(dp(6), dp(4), dp(6), dp(4));
        return btn;
    }

    private View buildHistoryTab() {
        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(Color.parseColor("#F4F6F4"));
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(dp(14), dp(14), dp(14), dp(22));

        LinearLayout headerRow = new LinearLayout(this);
        headerRow.setOrientation(LinearLayout.HORIZONTAL);
        headerRow.setGravity(Gravity.CENTER_VERTICAL);

        TextView title = new TextView(this);
        title.setText("Recent Commands");
        title.setTextColor(Color.parseColor("#18181B"));
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        headerRow.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        Button refreshBtn = createButton("Refresh", "#FFFFFF", "#065F46", "#A7F3D0", dp(10), 12);
        refreshBtn.setOnClickListener(v -> fetchCommandHistory(1));
        headerRow.addView(refreshBtn, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(36)));
        col.addView(headerRow);

        historyCardsContainer = new LinearLayout(this);
        historyCardsContainer.setOrientation(LinearLayout.VERTICAL);
        col.addView(historyCardsContainer, topMarginLp(dp(10)));

        historyLoadMoreBtn = createButton("Load More Commands", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 13);
        historyLoadMoreBtn.setVisibility(View.GONE);
        historyLoadMoreBtn.setOnClickListener(v -> {
            if (currentHistoryPage < lastHistoryPage) {
                fetchCommandHistory(currentHistoryPage + 1);
            }
        });
        LinearLayout.LayoutParams moreLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(44));
        moreLp.topMargin = dp(12);
        col.addView(historyLoadMoreBtn, moreLp);

        scroll.addView(col);
        return scroll;
    }

    private View buildSettingsTab() {
        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(Color.parseColor("#F4F6F4"));
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(dp(14), dp(14), dp(14), dp(22));

        LinearLayout accountCard = createLightCard();
        TextView aTitle = new TextView(this);
        aTitle.setText("AUTHENTICATED ADMINISTRATOR");
        aTitle.setTextColor(Color.parseColor("#71717A"));
        aTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        aTitle.setTypeface(Typeface.DEFAULT_BOLD);
        accountCard.addView(aTitle);

        accountNameText = new TextView(this);
        accountNameText.setTextColor(Color.parseColor("#18181B"));
        accountNameText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        accountNameText.setTypeface(Typeface.DEFAULT_BOLD);
        accountCard.addView(accountNameText, topMarginLp(dp(4)));

        accountEmailText = new TextView(this);
        accountEmailText.setTextColor(Color.parseColor("#52525B"));
        accountEmailText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        accountCard.addView(accountEmailText, topMarginLp(dp(2)));

        Button logoutBtn = createButton("Sign Out (Revoke Token)", "#FEF2F2", "#B91C1C", "#FECACA", dp(12), 13);
        logoutBtn.setOnClickListener(v -> performLogout());
        LinearLayout.LayoutParams outLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(44));
        outLp.topMargin = dp(14);
        accountCard.addView(logoutBtn, outLp);
        col.addView(accountCard);

        LinearLayout connCard = createLightCard();
        TextView cTitle = new TextView(this);
        cTitle.setText("Connection Endpoints");
        cTitle.setTextColor(Color.parseColor("#18181B"));
        cTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        cTitle.setTypeface(Typeface.DEFAULT_BOLD);
        connCard.addView(cTitle);

        connCard.addView(createFieldLabel("Active Robot ID"), topMarginLp(dp(12)));
        settingsRobotIdInput = createInput(getRobotId(), "ROBOT-001", InputType.TYPE_CLASS_TEXT);
        connCard.addView(settingsRobotIdInput, topMarginLp(dp(6)));

        connCard.addView(createFieldLabel("Laravel Sanctum API Base URL"), topMarginLp(dp(12)));
        settingsApiUrlInput = createInput(getBaseUrl(), "https://your-app.up.railway.app",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        connCard.addView(settingsApiUrlInput, topMarginLp(dp(6)));

        connCard.addView(createFieldLabel("Direct ESP32 Robot Controller URL"), topMarginLp(dp(12)));
        settingsDirectUrlInput = createInput(getDirectUrl(), "http://192.168.4.1",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        connCard.addView(settingsDirectUrlInput, topMarginLp(dp(6)));

        Button saveBtn = createButton("Save Connection Settings", "#047857", "#FFFFFF", "#065F46", dp(12), 13);
        saveBtn.setOnClickListener(v -> {
            String normalizedApi = normalizeUrl(settingsApiUrlInput.getText().toString(), false);
            String normalizedDirect = normalizeUrl(settingsDirectUrlInput.getText().toString(), true);
            String robotId = settingsRobotIdInput.getText().toString().trim();
            if (robotId.isEmpty()) {
                robotId = "ROBOT-001";
            }
            prefs.edit()
                    .putString(KEY_ROBOT_ID, robotId)
                    .putString(KEY_BASE_URL, normalizedApi)
                    .putString(KEY_DIRECT_URL, normalizedDirect)
                    .apply();
            settingsRobotIdInput.setText(robotId);
            settingsApiUrlInput.setText(normalizedApi);
            settingsDirectUrlInput.setText(normalizedDirect);
            telemetryRobotText.setText(getRobotId());
            updateHeaderSubtitle();
            showBanner("Connection settings saved.", false);
            fetchStatus(true);
        });
        LinearLayout.LayoutParams saveLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(46));
        saveLp.topMargin = dp(16);
        connCard.addView(saveBtn, saveLp);

        Button testDirectBtn = createButton("Test Direct ESP32 Connection", "#FFFFFF", "#065F46", "#A7F3D0", dp(12), 13);
        testDirectBtn.setOnClickListener(v -> testDirectConnection());
        LinearLayout.LayoutParams testLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(42));
        testLp.topMargin = dp(8);
        connCard.addView(testDirectBtn, testLp);

        directTestResultText = new TextView(this);
        directTestResultText.setTextColor(Color.parseColor("#71717A"));
        directTestResultText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        directTestResultText.setVisibility(View.GONE);
        connCard.addView(directTestResultText, topMarginLp(dp(6)));

        col.addView(connCard, topMarginLp(dp(12)));
        scroll.addView(col);
        return scroll;
    }

    private void testDirectConnection() {
        String target = normalizeUrl(settingsDirectUrlInput.getText().toString(), true);
        directTestResultText.setVisibility(View.VISIBLE);
        directTestResultText.setTextColor(Color.parseColor("#52525B"));
        directTestResultText.setText("Pinging " + target + "...");
        executor.execute(() -> {
            HttpURLConnection conn = null;
            try {
                URL url = new URL(target + "/arm/config");
                conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("GET");
                conn.setConnectTimeout(2500);
                conn.setReadTimeout(2500);
                int code = conn.getResponseCode();
                runOnUiThread(() -> {
                    boolean ok = code >= 200 && code < 400;
                    directTestResultText.setTextColor(Color.parseColor(ok ? "#065F46" : "#92400E"));
                    directTestResultText.setText(ok
                            ? "ESP32 reachable at " + target + " (HTTP " + code + ")."
                            : "ESP32 responded with HTTP " + code + " at " + target + ".");
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    directTestResultText.setTextColor(Color.parseColor("#991B1B"));
                    directTestResultText.setText("Cannot reach " + target + ". Make sure your phone is connected to the robot's Wi-Fi AP.");
                });
            } finally {
                if (conn != null) {
                    conn.disconnect();
                }
            }
        });
    }

    private void switchTab(int index) {
        activeTabIndex = index;
        tabControllerView.setVisibility(index == 0 ? View.VISIBLE : View.GONE);
        tabHistoryView.setVisibility(index == 1 ? View.VISIBLE : View.GONE);
        tabSettingsView.setVisibility(index == 2 ? View.VISIBLE : View.GONE);

        styleNavButton(navControllerBtn, index == 0);
        styleNavButton(navHistoryBtn, index == 1);
        styleNavButton(navSettingsBtn, index == 2);
    }

    private Button createNavTabButton(String label, Runnable onClick) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        b.setOnClickListener(v -> onClick.run());
        return b;
    }

    private void styleNavButton(Button b, boolean active) {
        b.setTextColor(Color.parseColor(active ? "#FFFFFF" : "#9CA3AF"));
        b.setTypeface(active ? Typeface.DEFAULT_BOLD : Typeface.DEFAULT);
        b.setBackground(roundedBox(active ? "#065F46" : "#111E1B", active ? "#10B981" : "#1E3A32", dp(10)));
    }

    private void refreshAuthState() {
        updateHeaderSubtitle();
        boolean authed = isAuthenticated();
        headerSyncBtn.setVisibility(authed ? View.VISIBLE : View.GONE);
        headerRotateBtn.setVisibility(authed ? View.VISIBLE : View.GONE);

        if (authed) {
            loginScreen.setVisibility(View.GONE);
            mainShell.setVisibility(View.VISIBLE);
            accountNameText.setText(prefs.getString(KEY_USER_NAME, "Administrator"));
            accountEmailText.setText(prefs.getString(KEY_USER_EMAIL, ""));
            settingsApiUrlInput.setText(getBaseUrl());
            settingsDirectUrlInput.setText(getDirectUrl());
            settingsRobotIdInput.setText(getRobotId());
            telemetryRobotText.setText(getRobotId());
            fetchStatus(true);
            fetchCommandHistory(1);
            startAutoPolling();
        } else {
            stopAutoPolling();
            mainShell.setVisibility(View.GONE);
            loginScreen.setVisibility(View.VISIBLE);
            loginServerInput.setText(prefs.getString(KEY_BASE_URL, ""));
            loginEmailInput.setText(prefs.getString(KEY_LAST_EMAIL, ""));
            loginPasswordInput.setText("");
        }
    }

    private void handleSessionExpired() {
        prefs.edit().remove(KEY_TOKEN).apply();
        showBanner("Session expired. Please sign in again.", true);
        refreshAuthState();
    }

    private void performLogin() {
        emailErrorText.setVisibility(View.GONE);
        passwordErrorText.setVisibility(View.GONE);
        statusBanner.setVisibility(View.GONE);

        String rawUrl = loginServerInput.getText().toString().trim();
        String email = loginEmailInput.getText().toString().trim();
        String password = loginPasswordInput.getText().toString();

        if (rawUrl.isEmpty()) {
            showBanner("Please enter your Laravel Server URL first.", true);
            return;
        }
        String serverUrl = normalizeUrl(rawUrl, false);
        loginServerInput.setText(serverUrl);
        prefs.edit()
                .putString(KEY_BASE_URL, serverUrl)
                .putString(KEY_LAST_EMAIL, email)
                .apply();
        updateHeaderSubtitle();

        loginSubmitBtn.setEnabled(false);
        loginSubmitBtn.setText("Signing in...");

        executor.execute(() -> {
            try {
                JSONObject payload = new JSONObject();
                payload.put("email", email);
                payload.put("password", password);
                payload.put("device_name", "RobotSys-Android-Native");

                ApiResponse res = requestApi("POST", "/api/v1/mobile/login", payload.toString(), false);
                runOnUiThread(() -> {
                    loginSubmitBtn.setEnabled(true);
                    loginSubmitBtn.setText("Sign In & Launch Controller");
                    handleLoginResponse(res);
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    loginSubmitBtn.setEnabled(true);
                    loginSubmitBtn.setText("Sign In & Launch Controller");
                    showBanner("Cannot reach Laravel API at " + getBaseUrl() + " (" + e.getMessage() + ")", true);
                });
            }
        });
    }

    private void handleLoginResponse(ApiResponse res) {
        try {
            JSONObject json = new JSONObject(res.body);
            if (res.code == 200 && json.has("token")) {
                JSONObject user = json.optJSONObject("user");
                String userEmail = user != null ? user.optString("email", "") : "";
                prefs.edit()
                        .putString(KEY_TOKEN, json.getString("token"))
                        .putString(KEY_USER_NAME, user != null ? user.optString("name", "Administrator") : "Administrator")
                        .putString(KEY_USER_EMAIL, userEmail)
                        .putString(KEY_LAST_EMAIL, userEmail)
                        .apply();
                showBanner("Signed in securely.", false);
                refreshAuthState();
            } else if (res.code == 422) {
                JSONObject errors = json.optJSONObject("errors");
                if (errors != null) {
                    if (errors.has("email")) {
                        emailErrorText.setText(errors.getJSONArray("email").optString(0));
                        emailErrorText.setVisibility(View.VISIBLE);
                    }
                    if (errors.has("password")) {
                        passwordErrorText.setText(errors.getJSONArray("password").optString(0));
                        passwordErrorText.setVisibility(View.VISIBLE);
                    }
                } else {
                    showBanner(json.optString("message", "Validation failed."), true);
                }
            } else {
                showBanner(json.optString("message", "Sign in failed (HTTP " + res.code + ")."), true);
            }
        } catch (Exception e) {
            showBanner("Unexpected server response (HTTP " + res.code + "). Check your Server URL.", true);
        }
    }

    private void performLogout() {
        executor.execute(() -> {
            try {
                requestApi("POST", "/api/v1/mobile/logout", "{}", true);
            } catch (Exception ignored) {
            }
            runOnUiThread(() -> {
                prefs.edit().remove(KEY_TOKEN).apply();
                showBanner("Signed out.", false);
                refreshAuthState();
            });
        });
    }

    private void fetchStatus(boolean showSyncIndicator) {
        if (showSyncIndicator) {
            headerSyncBtn.setEnabled(false);
            headerSyncBtn.setText("...");
        }
        executor.execute(() -> {
            try {
                ApiResponse res = requestApi("GET",
                        "/api/v1/mobile/status?robot_id=" + Uri.encode(getRobotId()), null, true);
                runOnUiThread(() -> {
                    if (showSyncIndicator) {
                        headerSyncBtn.setEnabled(true);
                        headerSyncBtn.setText("Sync");
                    }
                    if (res.code == 401) {
                        handleSessionExpired();
                        return;
                    }
                    try {
                        JSONObject json = new JSONObject(res.body);
                        telemetryRobotText.setText(json.optString("robot_id", getRobotId()));
                        JSONObject tel = json.optJSONObject("telemetry");
                        if (tel != null) {
                            String movement = tel.optString("movement_status", "Active");
                            telemetryStatusPill.setText(movement);
                            String low = movement.toLowerCase(Locale.US);
                            if (low.contains("stop")) {
                                styleStatusPill(telemetryStatusPill, "danger");
                            } else if (low.contains("idle") || low.contains("reset") || low.contains("pause")) {
                                styleStatusPill(telemetryStatusPill, "warning");
                            } else {
                                styleStatusPill(telemetryStatusPill, "success");
                            }

                            int bat = (int) Math.max(0, Math.min(100, Math.round(tel.optDouble("battery_level", 0))));
                            telemetryBatteryText.setText(bat + "%");
                            updateBatteryBar(bat);

                            telemetryPlasticBinText.setText("Plastic: " + tel.optString("plastic_bin_level", "--"));
                            telemetryGeneralBinText.setText("General: " + tel.optString("general_bin_level", "--"));
                            telemetryUpdatedText.setText("Updated: " + tel.optString("updated_human", "Recently"));
                        } else {
                            telemetryStatusPill.setText("No Telemetry");
                            styleStatusPill(telemetryStatusPill, "neutral");
                            telemetryBatteryText.setText("--%");
                            updateBatteryBar(0);
                            telemetryPlasticBinText.setText("Plastic: --");
                            telemetryGeneralBinText.setText("General: --");
                            telemetryUpdatedText.setText("Waiting for robot telemetry");
                        }
                    } catch (Exception ignored) {
                    }
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    if (showSyncIndicator) {
                        headerSyncBtn.setEnabled(true);
                        headerSyncBtn.setText("Sync");
                    }
                    telemetryStatusPill.setText("Offline");
                    styleStatusPill(telemetryStatusPill, "warning");
                    telemetryUpdatedText.setText("Cannot reach " + getBaseUrl());
                });
            }
        });
    }

    private void updateBatteryBar(int percent) {
        if (batteryBarFill == null) {
            return;
        }
        batteryBarFill.post(() -> {
            View parent = (View) batteryBarFill.getParent();
            int totalWidth = parent != null ? parent.getWidth() : 0;
            if (totalWidth > 0) {
                ViewGroup.LayoutParams lp = batteryBarFill.getLayoutParams();
                lp.width = Math.round(totalWidth * (percent / 100f));
                batteryBarFill.setLayoutParams(lp);
            }
            String colorHex = percent <= 20 ? "#EF4444" : (percent <= 45 ? "#F59E0B" : "#10B981");
            batteryBarFill.setBackground(roundedBox(colorHex, colorHex, dp(99)));
        });
    }

    private void sendApiCommand(String command) {
        executor.execute(() -> {
            try {
                JSONObject payload = new JSONObject();
                payload.put("robot_id", getRobotId());
                payload.put("command", command);
                ApiResponse res = requestApi("POST", "/api/v1/mobile/commands", payload.toString(), true);
                runOnUiThread(() -> {
                    if (res.code == 401) {
                        handleSessionExpired();
                        return;
                    }
                    try {
                        JSONObject json = new JSONObject(res.body);
                        boolean isError = res.code >= 400;
                        showBanner(json.optString("message", "Command " + command + " queued."), isError);
                        if (activeTabIndex == 1) {
                            fetchCommandHistory(1);
                        }
                    } catch (Exception e) {
                        showBanner("Command response HTTP " + res.code, res.code >= 400);
                    }
                });
            } catch (Exception e) {
                runOnUiThread(() -> showBanner("Cloud queue offline for " + command + " (direct Wi-Fi still active).", true));
            }
        });
    }

    private void sendDirectCommand(String cmd) {
        sendDirectPath("/cmd?c=" + Uri.encode(cmd), cmd);
    }

    private void sendDirectPath(String path, String label) {
        directStatusText.setTextColor(Color.parseColor("#6EE7B7"));
        directStatusText.setText("Sending " + label + "...");
        executor.execute(() -> {
            HttpURLConnection conn = null;
            try {
                URL url = new URL(getDirectUrl() + path);
                conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("GET");
                conn.setConnectTimeout(2500);
                conn.setReadTimeout(2500);
                int code = conn.getResponseCode();
                runOnUiThread(() -> {
                    directStatusText.setTextColor(Color.parseColor(code == 200 ? "#6EE7B7" : "#FCD34D"));
                    directStatusText.setText(code == 200 ? "Sent: " + label : "ESP32 HTTP " + code);
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    directStatusText.setTextColor(Color.parseColor("#FCA5A5"));
                    directStatusText.setText("ESP32 AP unreachable");
                });
            } finally {
                if (conn != null) {
                    conn.disconnect();
                }
            }
        });
    }

    private void fetchCommandHistory(int page) {
        executor.execute(() -> {
            try {
                ApiResponse res = requestApi("GET",
                        "/api/v1/mobile/commands?robot_id=" + Uri.encode(getRobotId()) + "&page=" + page, null, true);
                runOnUiThread(() -> {
                    if (res.code == 401) {
                        handleSessionExpired();
                        return;
                    }
                    if (res.code != 200) {
                        return;
                    }
                    try {
                        JSONObject json = new JSONObject(res.body);
                        JSONArray data = json.optJSONArray("data");
                        JSONObject meta = json.optJSONObject("meta");
                        currentHistoryPage = meta != null ? meta.optInt("current_page", page) : page;
                        lastHistoryPage = meta != null ? meta.optInt("last_page", 1) : 1;

                        if (page == 1) {
                            historyCardsContainer.removeAllViews();
                        }
                        if (data == null || data.length() == 0) {
                            if (page == 1) {
                                TextView empty = new TextView(this);
                                empty.setText("No commands queued yet for " + getRobotId() + ".");
                                empty.setTextColor(Color.parseColor("#71717A"));
                                empty.setPadding(dp(8), dp(16), dp(8), dp(16));
                                historyCardsContainer.addView(empty);
                            }
                        } else {
                            for (int i = 0; i < data.length(); i++) {
                                JSONObject item = data.getJSONObject(i);
                                historyCardsContainer.addView(buildCommandCard(item), topMarginLp(dp(8)));
                            }
                        }
                        historyLoadMoreBtn.setVisibility(currentHistoryPage < lastHistoryPage ? View.VISIBLE : View.GONE);
                    } catch (Exception ignored) {
                    }
                });
            } catch (Exception ignored) {
            }
        });
    }

    private View buildCommandCard(JSONObject item) {
        LinearLayout card = createLightCard();
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout left = new LinearLayout(this);
        left.setOrientation(LinearLayout.VERTICAL);

        TextView cmdText = new TextView(this);
        cmdText.setText(item.optString("command", "COMMAND"));
        cmdText.setTextColor(Color.parseColor("#18181B"));
        cmdText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        cmdText.setTypeface(Typeface.DEFAULT_BOLD);
        left.addView(cmdText);

        TextView metaText = new TextView(this);
        metaText.setText(item.optString("robot_id", "ROBOT-001") + " • " + item.optString("created_human", "Recently"));
        metaText.setTextColor(Color.parseColor("#71717A"));
        metaText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        left.addView(metaText, topMarginLp(dp(2)));

        row.addView(left, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        String status = item.optString("status", "pending");
        TextView badge = new TextView(this);
        badge.setText(status);
        badge.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        badge.setTypeface(Typeface.DEFAULT_BOLD);
        badge.setPadding(dp(10), dp(4), dp(10), dp(4));
        if ("completed".equalsIgnoreCase(status) || "received".equalsIgnoreCase(status)
                || "executed".equalsIgnoreCase(status) || "acknowledged".equalsIgnoreCase(status)) {
            styleStatusPill(badge, "success");
        } else if ("cancelled".equalsIgnoreCase(status) || "expired".equalsIgnoreCase(status)
                || "failed".equalsIgnoreCase(status)) {
            styleStatusPill(badge, "danger");
        } else {
            styleStatusPill(badge, "warning");
        }
        row.addView(badge);
        card.addView(row);
        return card;
    }

    private ApiResponse requestApi(String method, String path, String jsonBody, boolean withAuth) throws Exception {
        HttpURLConnection conn = null;
        try {
            URL url = new URL(getBaseUrl() + path);
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod(method);
            conn.setConnectTimeout(6000);
            conn.setReadTimeout(6000);
            conn.setRequestProperty("Accept", "application/json");
            conn.setRequestProperty("Content-Type", "application/json");
            if (withAuth) {
                conn.setRequestProperty("Authorization", "Bearer " + prefs.getString(KEY_TOKEN, ""));
            }
            if (jsonBody != null) {
                conn.setDoOutput(true);
                byte[] bytes = jsonBody.getBytes(StandardCharsets.UTF_8);
                try (OutputStream os = conn.getOutputStream()) {
                    os.write(bytes);
                }
            }
            int code = conn.getResponseCode();
            InputStream is = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
            StringBuilder sb = new StringBuilder();
            if (is != null) {
                try (BufferedReader br = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8))) {
                    String line;
                    while ((line = br.readLine()) != null) {
                        sb.append(line);
                    }
                }
            }
            return new ApiResponse(code, sb.toString());
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }

    private static class ApiResponse {
        final int code;
        final String body;

        ApiResponse(int code, String body) {
            this.code = code;
            this.body = body;
        }
    }

    private String normalizeUrl(String raw, boolean preferHttp) {
        String cleaned = raw == null ? "" : raw.replaceAll("\\s+", "").replaceAll("/+$", "");
        if (cleaned.isEmpty()) {
            return preferHttp ? DEFAULT_DIRECT_URL : "";
        }
        if (!cleaned.startsWith("http://") && !cleaned.startsWith("https://")) {
            boolean isLocal = preferHttp
                    || cleaned.startsWith("192.168.")
                    || cleaned.startsWith("10.")
                    || cleaned.startsWith("172.")
                    || cleaned.startsWith("127.")
                    || cleaned.startsWith("localhost");
            cleaned = (isLocal ? "http://" : "https://") + cleaned;
        }
        return cleaned;
    }

    private boolean isAuthenticated() {
        String token = prefs.getString(KEY_TOKEN, "");
        return token != null && !token.isEmpty();
    }

    private String getBaseUrl() {
        return prefs.getString(KEY_BASE_URL, "").replaceAll("/+$", "");
    }

    private String getDirectUrl() {
        String url = prefs.getString(KEY_DIRECT_URL, DEFAULT_DIRECT_URL).replaceAll("/+$", "");
        return url.isEmpty() ? DEFAULT_DIRECT_URL : url;
    }

    private String getRobotId() {
        String id = prefs.getString(KEY_ROBOT_ID, "ROBOT-001");
        return id != null && !id.isEmpty() ? id : "ROBOT-001";
    }

    private void updateHeaderSubtitle() {
        String base = getBaseUrl().replaceFirst("^https?://", "");
        if (base.isEmpty()) {
            headerSubtitle.setText("Dual-Hand Robotics Controller");
        } else {
            headerSubtitle.setText(getRobotId() + " • " + base);
        }
    }

    private void showBanner(String message, boolean isError) {
        if (hideBannerRunnable != null) {
            mainHandler.removeCallbacks(hideBannerRunnable);
        }
        statusBanner.setText(message);
        statusBanner.setTextColor(Color.parseColor(isError ? "#991B1B" : "#065F46"));
        statusBanner.setBackgroundColor(Color.parseColor(isError ? "#FEF2F2" : "#ECFDF5"));
        statusBanner.setVisibility(View.VISIBLE);
        if (!isError) {
            hideBannerRunnable = () -> statusBanner.setVisibility(View.GONE);
            mainHandler.postDelayed(hideBannerRunnable, 3500L);
        }
    }

    private void styleStatusPill(TextView pill, String tone) {
        if ("success".equals(tone)) {
            pill.setTextColor(Color.parseColor("#065F46"));
            pill.setBackground(roundedBox("#ECFDF5", "#A7F3D0", dp(99)));
        } else if ("danger".equals(tone)) {
            pill.setTextColor(Color.parseColor("#991B1B"));
            pill.setBackground(roundedBox("#FEF2F2", "#FECACA", dp(99)));
        } else if ("warning".equals(tone)) {
            pill.setTextColor(Color.parseColor("#92400E"));
            pill.setBackground(roundedBox("#FFFBEB", "#FDE68A", dp(99)));
        } else {
            pill.setTextColor(Color.parseColor("#52525B"));
            pill.setBackground(roundedBox("#F4F4F5", "#E4E4E7", dp(99)));
        }
    }

    private TextView createDarkMetricChip(String text) {
        TextView tv = new TextView(this);
        tv.setText(text);
        tv.setTextColor(Color.parseColor("#E5E7EB"));
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        tv.setTypeface(Typeface.DEFAULT_BOLD);
        tv.setGravity(Gravity.CENTER);
        tv.setPadding(dp(8), dp(6), dp(8), dp(6));
        tv.setBackground(roundedBox("#09110F", "#1F352E", dp(10)));
        return tv;
    }

    private LinearLayout createDarkCard(String fillHex, String strokeHex) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(12), dp(12), dp(12), dp(12));
        card.setBackground(roundedBox(fillHex, strokeHex, dp(16)));
        return card;
    }

    private LinearLayout createLightCard() {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(16), dp(16), dp(16), dp(16));
        card.setBackground(roundedBox("#FFFFFF", "#E4E4E7", dp(16)));
        return card;
    }

    private TextView createFieldLabel(String text) {
        TextView tv = new TextView(this);
        tv.setText(text);
        tv.setTextColor(Color.parseColor("#27272A"));
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        tv.setTypeface(Typeface.DEFAULT_BOLD);
        return tv;
    }

    private TextView createErrorText() {
        TextView tv = new TextView(this);
        tv.setTextColor(Color.parseColor("#DC2626"));
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        tv.setVisibility(View.GONE);
        return tv;
    }

    private EditText createInput(String initial, String hint, int inputType) {
        EditText et = new EditText(this);
        et.setText(initial);
        et.setHint(hint);
        et.setHintTextColor(Color.parseColor("#A1A1AA"));
        et.setInputType(inputType);
        et.setTextColor(Color.parseColor("#18181B"));
        et.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        et.setPadding(dp(12), dp(11), dp(12), dp(11));
        et.setBackground(roundedBox("#FFFFFF", "#D4D4D8", dp(12)));
        return et;
    }

    private Button createButton(String label, String fillHex, String textHex, String strokeHex, int radiusPx, int textSp) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, textSp);
        b.setTypeface(Typeface.DEFAULT_BOLD);
        b.setTextColor(Color.parseColor(textHex));
        b.setBackground(roundedBox(fillHex, strokeHex, radiusPx));
        return b;
    }

    private GradientDrawable roundedBox(String fillHex, String strokeHex, int radiusPx) {
        GradientDrawable d = new GradientDrawable();
        d.setColor(Color.parseColor(fillHex));
        d.setCornerRadius(radiusPx);
        d.setStroke(dp(1), Color.parseColor(strokeHex));
        return d;
    }

    private LinearLayout.LayoutParams topMarginLp(int topMargin) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = topMargin;
        return lp;
    }

    private int dp(int value) {
        return Math.round(TypedValue.applyDimension(
                TypedValue.COMPLEX_UNIT_DIP, value, getResources().getDisplayMetrics()));
    }
}
