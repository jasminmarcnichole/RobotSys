package com.robotsys.controller;

import android.app.Activity;
import android.content.SharedPreferences;
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

    private TextView headerSubtitle;
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
        getWindow().setNavigationBarColor(Color.parseColor("#FFFFFF"));

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setFitsSystemWindows(true);
        root.setBackgroundColor(Color.parseColor("#F4F6F4"));

        root.addView(buildTopHeader(), new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        statusBanner = new TextView(this);
        statusBanner.setPadding(dp(16), dp(11), dp(16), dp(11));
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
        refreshAuthState();
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

    private View buildTopHeader() {
        LinearLayout bar = new LinearLayout(this);
        bar.setOrientation(LinearLayout.HORIZONTAL);
        bar.setGravity(Gravity.CENTER_VERTICAL);
        bar.setPadding(dp(16), dp(14), dp(16), dp(14));
        bar.setBackgroundColor(Color.parseColor("#042F24"));

        TextView badge = new TextView(this);
        badge.setText("RS");
        badge.setTextColor(Color.WHITE);
        badge.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        badge.setTypeface(Typeface.DEFAULT_BOLD);
        badge.setGravity(Gravity.CENTER);
        badge.setBackground(roundedBox("#065F46", "#10B981", dp(10)));
        LinearLayout.LayoutParams badgeLp = new LinearLayout.LayoutParams(dp(38), dp(38));
        badgeLp.rightMargin = dp(12);
        bar.addView(badge, badgeLp);

        LinearLayout titles = new LinearLayout(this);
        titles.setOrientation(LinearLayout.VERTICAL);

        TextView title = new TextView(this);
        title.setText("RobotSys Controller");
        title.setTextColor(Color.WHITE);
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        titles.addView(title);

        headerSubtitle = new TextView(this);
        headerSubtitle.setTextColor(Color.parseColor("#A7F3D0"));
        headerSubtitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        titles.addView(headerSubtitle);

        bar.addView(titles, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        headerSyncBtn = createButton("Sync", "#065F46", "#FFFFFF", "#10B981", dp(18), 12);
        headerSyncBtn.setPadding(dp(14), 0, dp(14), 0);
        headerSyncBtn.setOnClickListener(v -> {
            if (isAuthenticated()) {
                fetchStatus(true);
                fetchCommandHistory(1);
            }
        });
        bar.addView(headerSyncBtn, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(34)));

        return bar;
    }

    private View buildLoginScreen() {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);

        LinearLayout container = new LinearLayout(this);
        container.setOrientation(LinearLayout.VERTICAL);
        container.setGravity(Gravity.CENTER_HORIZONTAL);
        container.setPadding(dp(20), dp(24), dp(20), dp(28));

        LinearLayout card = createCard();

        TextView eyebrow = new TextView(this);
        eyebrow.setText("SANCTUM SECURE SIGN-IN");
        eyebrow.setTextColor(Color.parseColor("#047857"));
        eyebrow.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        eyebrow.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(eyebrow);

        TextView heading = new TextView(this);
        heading.setText("Connect to RobotSys");
        heading.setTextColor(Color.parseColor("#18181B"));
        heading.setTextSize(TypedValue.COMPLEX_UNIT_SP, 22);
        heading.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(heading, topMarginLp(dp(4)));

        TextView sub = new TextView(this);
        sub.setText("Sign in with your administrator account to sync live telemetry and control the robot.");
        sub.setTextColor(Color.parseColor("#52525B"));
        sub.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        card.addView(sub, topMarginLp(dp(6)));

        card.addView(createFieldLabel("Laravel Server URL"), topMarginLp(dp(18)));
        loginServerInput = createInput(
                prefs.getString(KEY_BASE_URL, ""),
                "https://your-app.up.railway.app or http://192.168.x.x:8000",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI
        );
        card.addView(loginServerInput, topMarginLp(dp(6)));

        TextView urlHint = new TextView(this);
        urlHint.setText("Use your deployed HTTPS domain, or your PC's Wi-Fi IP (e.g. http://192.168.x.x:8000) for local testing.");
        urlHint.setTextColor(Color.parseColor("#71717A"));
        urlHint.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        card.addView(urlHint, topMarginLp(dp(4)));

        card.addView(createFieldLabel("Administrator Email"), topMarginLp(dp(14)));
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

        loginSubmitBtn = createButton("Sign In & Connect", "#047857", "#FFFFFF", "#065F46", dp(14), 14);
        loginSubmitBtn.setOnClickListener(v -> performLogin());
        LinearLayout.LayoutParams btnLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(50));
        btnLp.topMargin = dp(20);
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
        divider.setBackgroundColor(Color.parseColor("#E4E4E7"));
        shell.addView(divider, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(1)));

        LinearLayout bottomNav = new LinearLayout(this);
        bottomNav.setOrientation(LinearLayout.HORIZONTAL);
        bottomNav.setPadding(dp(12), dp(8), dp(12), dp(10));
        bottomNav.setBackgroundColor(Color.WHITE);

        navControllerBtn = createNavTabButton("Controller", () -> switchTab(0));
        navHistoryBtn = createNavTabButton("Commands", () -> {
            switchTab(1);
            fetchCommandHistory(1);
        });
        navSettingsBtn = createNavTabButton("Settings", () -> switchTab(2));

        bottomNav.addView(navControllerBtn, new LinearLayout.LayoutParams(0, dp(44), 1f));
        LinearLayout.LayoutParams midLp = new LinearLayout.LayoutParams(0, dp(44), 1f);
        midLp.leftMargin = dp(8);
        midLp.rightMargin = dp(8);
        bottomNav.addView(navHistoryBtn, midLp);
        bottomNav.addView(navSettingsBtn, new LinearLayout.LayoutParams(0, dp(44), 1f));

        shell.addView(bottomNav, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        switchTab(0);
        return shell;
    }

    private View buildControllerTab() {
        ScrollView scroll = new ScrollView(this);
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(dp(14), dp(14), dp(14), dp(22));

        // 1. Live Telemetry Summary Card
        LinearLayout telemetryCard = createCard();
        LinearLayout tHeader = new LinearLayout(this);
        tHeader.setOrientation(LinearLayout.HORIZONTAL);
        tHeader.setGravity(Gravity.CENTER_VERTICAL);

        telemetryRobotText = new TextView(this);
        telemetryRobotText.setText(getRobotId());
        telemetryRobotText.setTextColor(Color.parseColor("#18181B"));
        telemetryRobotText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        telemetryRobotText.setTypeface(Typeface.DEFAULT_BOLD);
        tHeader.addView(telemetryRobotText, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        telemetryStatusPill = new TextView(this);
        telemetryStatusPill.setText("Awaiting Data");
        telemetryStatusPill.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        telemetryStatusPill.setTypeface(Typeface.DEFAULT_BOLD);
        telemetryStatusPill.setPadding(dp(10), dp(4), dp(10), dp(4));
        styleStatusPill(telemetryStatusPill, "neutral");
        tHeader.addView(telemetryStatusPill);
        telemetryCard.addView(tHeader);

        // Battery Row + Progress Bar
        LinearLayout batteryRow = new LinearLayout(this);
        batteryRow.setOrientation(LinearLayout.HORIZONTAL);
        batteryRow.setGravity(Gravity.CENTER_VERTICAL);

        TextView batLabel = new TextView(this);
        batLabel.setText("Battery Charge");
        batLabel.setTextColor(Color.parseColor("#52525B"));
        batLabel.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        batteryRow.addView(batLabel, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        telemetryBatteryText = new TextView(this);
        telemetryBatteryText.setText("--%");
        telemetryBatteryText.setTextColor(Color.parseColor("#18181B"));
        telemetryBatteryText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        telemetryBatteryText.setTypeface(Typeface.DEFAULT_BOLD);
        batteryRow.addView(telemetryBatteryText);
        telemetryCard.addView(batteryRow, topMarginLp(dp(12)));

        FrameLayout batteryTrack = new FrameLayout(this);
        batteryTrack.setBackground(roundedBox("#F4F4F5", "#E4E4E7", dp(99)));
        batteryBarFill = new View(this);
        batteryBarFill.setBackground(roundedBox("#10B981", "#10B981", dp(99)));
        batteryTrack.addView(batteryBarFill, new FrameLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT));
        LinearLayout.LayoutParams trackLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(8));
        trackLp.topMargin = dp(6);
        telemetryCard.addView(batteryTrack, trackLp);

        // Bin Level Chips
        LinearLayout binsRow = new LinearLayout(this);
        binsRow.setOrientation(LinearLayout.HORIZONTAL);

        telemetryPlasticBinText = createMetricChip("Plastic Bin: --");
        telemetryGeneralBinText = createMetricChip("General Bin: --");

        LinearLayout.LayoutParams chipLeft = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        chipLeft.rightMargin = dp(6);
        LinearLayout.LayoutParams chipRight = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        chipRight.leftMargin = dp(6);
        binsRow.addView(telemetryPlasticBinText, chipLeft);
        binsRow.addView(telemetryGeneralBinText, chipRight);
        telemetryCard.addView(binsRow, topMarginLp(dp(10)));

        telemetryUpdatedText = new TextView(this);
        telemetryUpdatedText.setText("Last report: checking...");
        telemetryUpdatedText.setTextColor(Color.parseColor("#71717A"));
        telemetryUpdatedText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        telemetryCard.addView(telemetryUpdatedText, topMarginLp(dp(8)));

        col.addView(telemetryCard);

        // 2. Sanctum API Command Queue Card (START / STOP / RESET)
        LinearLayout queueCard = createCard();
        TextView qTitle = new TextView(this);
        qTitle.setText("Cloud Command Queue");
        qTitle.setTextColor(Color.parseColor("#18181B"));
        qTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        qTitle.setTypeface(Typeface.DEFAULT_BOLD);
        queueCard.addView(qTitle);

        TextView qSub = new TextView(this);
        qSub.setText("Queued through Laravel Sanctum API for remote & autonomous operation.");
        qSub.setTextColor(Color.parseColor("#71717A"));
        qSub.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        queueCard.addView(qSub, topMarginLp(dp(2)));

        LinearLayout.LayoutParams halfLeft = new LinearLayout.LayoutParams(0, dp(48), 1f);
        halfLeft.rightMargin = dp(6);
        LinearLayout.LayoutParams halfRight = new LinearLayout.LayoutParams(0, dp(48), 1f);
        halfRight.leftMargin = dp(6);

        LinearLayout qRow = new LinearLayout(this);
        qRow.setOrientation(LinearLayout.HORIZONTAL);
        Button startBtn = createButton("START (AUTO)", "#047857", "#FFFFFF", "#065F46", dp(12), 13);
        startBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendApiCommand("START");
        });
        Button stopApiBtn = createButton("STOP", "#B91C1C", "#FFFFFF", "#991B1B", dp(12), 13);
        stopApiBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendApiCommand("STOP");
        });

        qRow.addView(startBtn, halfLeft);
        qRow.addView(stopApiBtn, halfRight);
        queueCard.addView(qRow, topMarginLp(dp(10)));

        Button resetBtn = createButton("RESET (MANUAL MODE)", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 13);
        resetBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendApiCommand("RESET");
        });
        LinearLayout.LayoutParams fullLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(44));
        fullLp.topMargin = dp(8);
        queueCard.addView(resetBtn, fullLp);

        col.addView(queueCard, topMarginLp(dp(12)));

        // 3. Direct Wi-Fi Steering & 6-DOF Arm D-Pad Card
        LinearLayout directCard = createCard();
        LinearLayout dHead = new LinearLayout(this);
        dHead.setOrientation(LinearLayout.HORIZONTAL);
        dHead.setGravity(Gravity.CENTER_VERTICAL);

        TextView dTitle = new TextView(this);
        dTitle.setText("Direct Steering & 6-DOF Arm");
        dTitle.setTextColor(Color.parseColor("#18181B"));
        dTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        dTitle.setTypeface(Typeface.DEFAULT_BOLD);
        dHead.addView(dTitle, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        directStatusText = new TextView(this);
        directStatusText.setText("Ready");
        directStatusText.setTextColor(Color.parseColor("#047857"));
        directStatusText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        dHead.addView(directStatusText);
        directCard.addView(dHead);

        TextView dHint = new TextView(this);
        dHint.setText("Hold a directional pad button to drive; release to stop immediately.");
        dHint.setTextColor(Color.parseColor("#71717A"));
        dHint.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        directCard.addView(dHint, topMarginLp(dp(2)));

        LinearLayout modeRow = new LinearLayout(this);
        modeRow.setOrientation(LinearLayout.HORIZONTAL);
        Button autoModeBtn = createButton("AUTO SENSOR (A)", "#065F46", "#FFFFFF", "#047857", dp(12), 12);
        autoModeBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("A");
        });
        Button manualModeBtn = createButton("MANUAL MODE (M)", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 12);
        manualModeBtn.setOnClickListener(v -> {
            v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
            sendDirectCommand("M");
        });
        modeRow.addView(autoModeBtn, halfLeft);
        modeRow.addView(manualModeBtn, halfRight);
        directCard.addView(modeRow, topMarginLp(dp(10)));

        // Recessed 3x3 Hold-to-Drive D-Pad
        LinearLayout padBox = new LinearLayout(this);
        padBox.setOrientation(LinearLayout.VERTICAL);
        padBox.setPadding(dp(10), dp(10), dp(10), dp(10));
        padBox.setBackground(roundedBox("#F4F4F5", "#E4E4E7", dp(16)));

        padBox.addView(buildDpadRow(null, "FORWARD", "F", false, null));
        padBox.addView(buildDpadRow("LEFT", "STOP", "S", true, "RIGHT"), topMarginLp(dp(8)));
        padBox.addView(buildDpadRow(null, "BACKWARD", "B", false, null), topMarginLp(dp(8)));
        directCard.addView(padBox, topMarginLp(dp(12)));

        // Gripper & Bin Actions
        LinearLayout gripRow1 = new LinearLayout(this);
        gripRow1.setOrientation(LinearLayout.HORIZONTAL);
        Button openGripBtn = createButton("Open Gripper", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 12);
        openGripBtn.setOnClickListener(v -> sendDirectCommand("O"));
        Button closeGripBtn = createButton("Close Gripper", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 12);
        closeGripBtn.setOnClickListener(v -> sendDirectCommand("C"));
        gripRow1.addView(openGripBtn, halfLeft);
        gripRow1.addView(closeGripBtn, halfRight);
        directCard.addView(gripRow1, topMarginLp(dp(10)));

        LinearLayout gripRow2 = new LinearLayout(this);
        gripRow2.setOrientation(LinearLayout.HORIZONTAL);
        Button grabPlasticBtn = createButton("Grab -> Plastic", "#ECFDF5", "#065F46", "#A7F3D0", dp(12), 12);
        grabPlasticBtn.setOnClickListener(v -> sendDirectCommand("P"));
        Button grabGeneralBtn = createButton("Grab -> General", "#F4F4F5", "#27272A", "#D4D4D8", dp(12), 12);
        grabGeneralBtn.setOnClickListener(v -> sendDirectCommand("G"));
        gripRow2.addView(grabPlasticBtn, halfLeft);
        gripRow2.addView(grabGeneralBtn, halfRight);
        directCard.addView(gripRow2, topMarginLp(dp(8)));

        LinearLayout baseRow = new LinearLayout(this);
        baseRow.setOrientation(LinearLayout.HORIZONTAL);
        Button baseLeftBtn = createButton("Base +5°", "#FFFFFF", "#27272A", "#D4D4D8", dp(10), 11);
        baseLeftBtn.setOnClickListener(v -> sendDirectCommand("["));
        Button homeArmBtn = createButton("Home Arm (H)", "#FFFFFF", "#27272A", "#D4D4D8", dp(10), 11);
        homeArmBtn.setOnClickListener(v -> sendDirectCommand("H"));
        Button baseRightBtn = createButton("Base -5°", "#FFFFFF", "#27272A", "#D4D4D8", dp(10), 11);
        baseRightBtn.setOnClickListener(v -> sendDirectCommand("]"));

        LinearLayout.LayoutParams third1 = new LinearLayout.LayoutParams(0, dp(42), 1f);
        LinearLayout.LayoutParams third2 = new LinearLayout.LayoutParams(0, dp(42), 1f);
        third2.leftMargin = dp(6);
        third2.rightMargin = dp(6);
        LinearLayout.LayoutParams third3 = new LinearLayout.LayoutParams(0, dp(42), 1f);
        baseRow.addView(baseLeftBtn, third1);
        baseRow.addView(homeArmBtn, third2);
        baseRow.addView(baseRightBtn, third3);
        directCard.addView(baseRow, topMarginLp(dp(8)));

        col.addView(directCard, topMarginLp(dp(12)));
        scroll.addView(col);
        return scroll;
    }

    private LinearLayout buildDpadRow(String leftLabel, String centerLabel, String centerCmd, boolean centerDanger, String rightLabel) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);

        LinearLayout.LayoutParams c1 = new LinearLayout.LayoutParams(0, dp(58), 1f);
        LinearLayout.LayoutParams c2 = new LinearLayout.LayoutParams(0, dp(58), 1f);
        c2.leftMargin = dp(8);
        c2.rightMargin = dp(8);
        LinearLayout.LayoutParams c3 = new LinearLayout.LayoutParams(0, dp(58), 1f);

        if (leftLabel != null) {
            Button lBtn = createHoldDriveButton(leftLabel, "L");
            row.addView(lBtn, c1);
        } else {
            row.addView(new View(this), c1);
        }

        if (centerDanger) {
            Button midBtn = createButton(centerLabel, "#B91C1C", "#FFFFFF", "#991B1B", dp(14), 12);
            midBtn.setOnClickListener(v -> {
                v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
                sendDirectCommand(centerCmd);
            });
            row.addView(midBtn, c2);
        } else {
            Button midBtn = createHoldDriveButton(centerLabel, centerCmd);
            row.addView(midBtn, c2);
        }

        if (rightLabel != null) {
            Button rBtn = createHoldDriveButton(rightLabel, "R");
            row.addView(rBtn, c3);
        } else {
            row.addView(new View(this), c3);
        }

        return row;
    }

    private Button createHoldDriveButton(String label, String cmd) {
        Button btn = createButton(label, "#FFFFFF", "#18181B", "#D4D4D8", dp(14), 12);
        btn.setOnTouchListener((v, event) -> {
            int action = event.getActionMasked();
            if (action == MotionEvent.ACTION_DOWN) {
                v.getParent().requestDisallowInterceptTouchEvent(true);
                v.performHapticFeedback(HapticFeedbackConstants.KEYBOARD_TAP);
                v.setBackground(roundedBox("#ECFDF5", "#047857", dp(14)));
                btn.setTextColor(Color.parseColor("#065F46"));
                sendDirectCommand(cmd);
                return true;
            } else if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
                v.getParent().requestDisallowInterceptTouchEvent(false);
                v.setBackground(roundedBox("#FFFFFF", "#D4D4D8", dp(14)));
                btn.setTextColor(Color.parseColor("#18181B"));
                sendDirectCommand("S");
                return true;
            }
            return false;
        });
        return btn;
    }

    private View buildHistoryTab() {
        ScrollView scroll = new ScrollView(this);
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
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(dp(14), dp(14), dp(14), dp(22));

        LinearLayout accountCard = createCard();
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

        LinearLayout connCard = createCard();
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
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        b.setOnClickListener(v -> onClick.run());
        return b;
    }

    private void styleNavButton(Button b, boolean active) {
        b.setTextColor(Color.parseColor(active ? "#065F46" : "#52525B"));
        b.setTypeface(active ? Typeface.DEFAULT_BOLD : Typeface.DEFAULT);
        b.setBackground(roundedBox(active ? "#ECFDF5" : "#F4F4F5", active ? "#A7F3D0" : "#E4E4E7", dp(12)));
    }

    private void refreshAuthState() {
        updateHeaderSubtitle();
        boolean authed = isAuthenticated();
        headerSyncBtn.setVisibility(authed ? View.VISIBLE : View.GONE);

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
                    loginSubmitBtn.setText("Sign In & Connect");
                    handleLoginResponse(res);
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    loginSubmitBtn.setEnabled(true);
                    loginSubmitBtn.setText("Sign In & Connect");
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

                            telemetryPlasticBinText.setText("Plastic Bin: " + tel.optString("plastic_bin_level", "--"));
                            telemetryGeneralBinText.setText("General Bin: " + tel.optString("general_bin_level", "--"));
                            telemetryUpdatedText.setText("Updated: " + tel.optString("updated_human", "Recently"));
                        } else {
                            telemetryStatusPill.setText("No Telemetry");
                            styleStatusPill(telemetryStatusPill, "neutral");
                            telemetryBatteryText.setText("--%");
                            updateBatteryBar(0);
                            telemetryPlasticBinText.setText("Plastic Bin: --");
                            telemetryGeneralBinText.setText("General Bin: --");
                            telemetryUpdatedText.setText("Waiting for first robot telemetry log");
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
            String colorHex = percent <= 20 ? "#DC2626" : (percent <= 45 ? "#D97706" : "#059669");
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
                        showBanner(json.optString("message", "Command " + command + " sent."), isError);
                        fetchCommandHistory(1);
                    } catch (Exception e) {
                        showBanner("Command response HTTP " + res.code, res.code >= 400);
                    }
                });
            } catch (Exception e) {
                runOnUiThread(() -> showBanner("Failed to queue " + command + ": " + e.getMessage(), true));
            }
        });
    }

    private void sendDirectCommand(String cmd) {
        directStatusText.setTextColor(Color.parseColor("#047857"));
        directStatusText.setText("Sending " + cmd + "...");
        executor.execute(() -> {
            HttpURLConnection conn = null;
            try {
                URL url = new URL(getDirectUrl() + "/cmd?c=" + Uri.encode(cmd));
                conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("GET");
                conn.setConnectTimeout(2500);
                conn.setReadTimeout(2500);
                int code = conn.getResponseCode();
                runOnUiThread(() -> {
                    directStatusText.setTextColor(Color.parseColor(code == 200 ? "#047857" : "#92400E"));
                    directStatusText.setText(code == 200 ? "Sent (" + cmd + ")" : "HTTP " + code);
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    directStatusText.setTextColor(Color.parseColor("#991B1B"));
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
        LinearLayout card = createCard();
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
            headerSubtitle.setText("Native Android Controller");
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
            mainHandler.postDelayed(hideBannerRunnable, 4000L);
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

    private TextView createMetricChip(String text) {
        TextView tv = new TextView(this);
        tv.setText(text);
        tv.setTextColor(Color.parseColor("#3F3F46"));
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        tv.setTypeface(Typeface.DEFAULT_BOLD);
        tv.setPadding(dp(10), dp(7), dp(10), dp(7));
        tv.setBackground(roundedBox("#F4F4F5", "#E4E4E7", dp(10)));
        return tv;
    }

    private LinearLayout createCard() {
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
