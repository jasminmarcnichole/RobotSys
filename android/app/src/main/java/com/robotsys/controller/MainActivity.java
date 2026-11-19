package com.robotsys.controller;

import android.app.Activity;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Bundle;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
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
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private static final String PREFS_NAME = "robotsys_native_prefs";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_DIRECT_URL = "direct_url";
    private static final String KEY_TOKEN = "sanctum_token";
    private static final String KEY_USER_NAME = "user_name";
    private static final String KEY_USER_EMAIL = "user_email";
    private static final String KEY_ROBOT_ID = "robot_id";

    private static final String DEFAULT_BASE_URL = "http://10.0.2.2:8000";
    private static final String DEFAULT_DIRECT_URL = "http://192.168.4.1";

    private final ExecutorService executor = Executors.newCachedThreadPool();
    private SharedPreferences prefs;

    private TextView headerSubtitle;
    private TextView statusBanner;

    private View loginScreen;
    private EditText loginServerInput;
    private EditText loginEmailInput;
    private EditText loginPasswordInput;
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
    private TextView telemetryBinsText;
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
        statusBanner.setPadding(dp(16), dp(10), dp(16), dp(10));
        statusBanner.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        statusBanner.setVisibility(View.GONE);
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
        LinearLayout.LayoutParams badgeLp = new LinearLayout.LayoutParams(dp(36), dp(36));
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

        Button syncBtn = createButton("Sync", "#065F46", "#FFFFFF", "#10B981", dp(18), 12);
        syncBtn.setPadding(dp(14), 0, dp(14), 0);
        syncBtn.setOnClickListener(v -> {
            if (isAuthenticated()) {
                fetchStatus();
                fetchCommandHistory(1);
            }
        });
        bar.addView(syncBtn, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(34)));

        return bar;
    }

    private View buildLoginScreen() {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);

        LinearLayout container = new LinearLayout(this);
        container.setOrientation(LinearLayout.VERTICAL);
        container.setGravity(Gravity.CENTER_HORIZONTAL);
        container.setPadding(dp(20), dp(24), dp(20), dp(24));

        LinearLayout card = createCard();

        TextView eyebrow = new TextView(this);
        eyebrow.setText("HEADLESS SANCTUM API");
        eyebrow.setTextColor(Color.parseColor("#047857"));
        eyebrow.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        eyebrow.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(eyebrow);

        TextView heading = new TextView(this);
        heading.setText("Sign in to Controller");
        heading.setTextColor(Color.parseColor("#18181B"));
        heading.setTextSize(TypedValue.COMPLEX_UNIT_SP, 22);
        heading.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(heading, topMarginLp(dp(4)));

        TextView sub = new TextView(this);
        sub.setText("Authenticate with your RobotSys administrator account to receive a Sanctum personal access token.");
        sub.setTextColor(Color.parseColor("#52525B"));
        sub.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        card.addView(sub, topMarginLp(dp(6)));

        card.addView(createFieldLabel("Laravel API Server URL"), topMarginLp(dp(16)));
        loginServerInput = createInput(getBaseUrl(), InputType.TYPE_TEXT_VARIATION_URI);
        card.addView(loginServerInput, topMarginLp(dp(6)));

        card.addView(createFieldLabel("Administrator Email"), topMarginLp(dp(14)));
        loginEmailInput = createInput("admin@example.com", InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        card.addView(loginEmailInput, topMarginLp(dp(6)));

        emailErrorText = createErrorText();
        card.addView(emailErrorText, topMarginLp(dp(4)));

        card.addView(createFieldLabel("Password"), topMarginLp(dp(12)));
        loginPasswordInput = createInput("password", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        card.addView(loginPasswordInput, topMarginLp(dp(6)));

        passwordErrorText = createErrorText();
        card.addView(passwordErrorText, topMarginLp(dp(4)));

        loginSubmitBtn = createButton("Sign In & Connect", "#047857", "#FFFFFF", "#065F46", dp(14), 14);
        loginSubmitBtn.setOnClickListener(v -> performLogin());
        LinearLayout.LayoutParams btnLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(48));
        btnLp.topMargin = dp(20);
        card.addView(loginSubmitBtn, btnLp);

        container.addView(card, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        scroll.addView(container);
        return scroll;
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

        LinearLayout bottomNav = new LinearLayout(this);
        bottomNav.setOrientation(LinearLayout.HORIZONTAL);
        bottomNav.setPadding(dp(12), dp(8), dp(12), dp(8));
        bottomNav.setBackgroundColor(Color.WHITE);

        navControllerBtn = createNavTabButton("Controller", () -> switchTab(0));
        navHistoryBtn = createNavTabButton("Commands", () -> switchTab(1));
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
        col.setPadding(dp(14), dp(14), dp(14), dp(20));

        // 1. Live Telemetry Summary Card
        LinearLayout telemetryCard = createCard();
        LinearLayout tHeader = new LinearLayout(this);
        tHeader.setOrientation(LinearLayout.HORIZONTAL);
        tHeader.setGravity(Gravity.CENTER_VERTICAL);

        telemetryRobotText = new TextView(this);
        telemetryRobotText.setText(getRobotId());
        telemetryRobotText.setTextColor(Color.parseColor("#18181B"));
        telemetryRobotText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        telemetryRobotText.setTypeface(Typeface.DEFAULT_BOLD);
        tHeader.addView(telemetryRobotText, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        telemetryStatusPill = new TextView(this);
        telemetryStatusPill.setText("Awaiting Data");
        telemetryStatusPill.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        telemetryStatusPill.setTypeface(Typeface.DEFAULT_BOLD);
        telemetryStatusPill.setTextColor(Color.parseColor("#065F46"));
        telemetryStatusPill.setPadding(dp(10), dp(4), dp(10), dp(4));
        telemetryStatusPill.setBackground(roundedBox("#ECFDF5", "#A7F3D0", dp(99)));
        tHeader.addView(telemetryStatusPill);
        telemetryCard.addView(tHeader);

        LinearLayout metricsRow = new LinearLayout(this);
        metricsRow.setOrientation(LinearLayout.HORIZONTAL);
        telemetryBatteryText = new TextView(this);
        telemetryBatteryText.setText("Battery: --%");
        telemetryBatteryText.setTextColor(Color.parseColor("#3F3F46"));
        telemetryBatteryText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        metricsRow.addView(telemetryBatteryText, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        telemetryBinsText = new TextView(this);
        telemetryBinsText.setText("Bins: -- / --");
        telemetryBinsText.setTextColor(Color.parseColor("#3F3F46"));
        telemetryBinsText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        metricsRow.addView(telemetryBinsText);
        telemetryCard.addView(metricsRow, topMarginLp(dp(8)));

        telemetryUpdatedText = new TextView(this);
        telemetryUpdatedText.setText("Last report: checking...");
        telemetryUpdatedText.setTextColor(Color.parseColor("#71717A"));
        telemetryUpdatedText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        telemetryCard.addView(telemetryUpdatedText, topMarginLp(dp(4)));

        col.addView(telemetryCard);

        // 2. Sanctum API Command Queue Card (START / STOP / RESET)
        LinearLayout queueCard = createCard();
        TextView qTitle = new TextView(this);
        qTitle.setText("Cloud Command Queue (Sanctum API)");
        qTitle.setTextColor(Color.parseColor("#18181B"));
        qTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        qTitle.setTypeface(Typeface.DEFAULT_BOLD);
        queueCard.addView(qTitle);

        LinearLayout qRow = new LinearLayout(this);
        qRow.setOrientation(LinearLayout.HORIZONTAL);
        Button startBtn = createButton("START (AUTO)", "#047857", "#FFFFFF", "#065F46", dp(12), 13);
        startBtn.setOnClickListener(v -> sendApiCommand("START"));
        Button stopApiBtn = createButton("STOP", "#B91C1C", "#FFFFFF", "#991B1B", dp(12), 13);
        stopApiBtn.setOnClickListener(v -> sendApiCommand("STOP"));

        LinearLayout.LayoutParams halfLeft = new LinearLayout.LayoutParams(0, dp(46), 1f);
        halfLeft.rightMargin = dp(6);
        LinearLayout.LayoutParams halfRight = new LinearLayout.LayoutParams(0, dp(46), 1f);
        halfRight.leftMargin = dp(6);
        qRow.addView(startBtn, halfLeft);
        qRow.addView(stopApiBtn, halfRight);
        queueCard.addView(qRow, topMarginLp(dp(10)));

        Button resetBtn = createButton("RESET (MANUAL MODE)", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 13);
        resetBtn.setOnClickListener(v -> sendApiCommand("RESET"));
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

        LinearLayout modeRow = new LinearLayout(this);
        modeRow.setOrientation(LinearLayout.HORIZONTAL);
        Button autoModeBtn = createButton("AUTO SENSOR (A)", "#065F46", "#FFFFFF", "#047857", dp(12), 12);
        autoModeBtn.setOnClickListener(v -> sendDirectCommand("A"));
        Button manualModeBtn = createButton("MANUAL MODE (M)", "#FFFFFF", "#27272A", "#D4D4D8", dp(12), 12);
        manualModeBtn.setOnClickListener(v -> sendDirectCommand("M"));
        modeRow.addView(autoModeBtn, halfLeft);
        modeRow.addView(manualModeBtn, halfRight);
        directCard.addView(modeRow, topMarginLp(dp(10)));

        // Recessed 3x3 D-Pad
        LinearLayout padBox = new LinearLayout(this);
        padBox.setOrientation(LinearLayout.VERTICAL);
        padBox.setPadding(dp(10), dp(10), dp(10), dp(10));
        padBox.setBackground(roundedBox("#F4F4F5", "#E4E4E7", dp(16)));

        padBox.addView(buildDpadRow(null, "FORWARD", "F", false, null));
        LinearLayout midDpad = buildDpadRow("LEFT", "STOP", "S", true, "RIGHT");
        padBox.addView(midDpad, topMarginLp(dp(8)));
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

        LinearLayout.LayoutParams third1 = new LinearLayout.LayoutParams(0, dp(40), 1f);
        LinearLayout.LayoutParams third2 = new LinearLayout.LayoutParams(0, dp(40), 1f);
        third2.leftMargin = dp(6);
        third2.rightMargin = dp(6);
        LinearLayout.LayoutParams third3 = new LinearLayout.LayoutParams(0, dp(40), 1f);
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

        LinearLayout.LayoutParams c1 = new LinearLayout.LayoutParams(0, dp(54), 1f);
        LinearLayout.LayoutParams c2 = new LinearLayout.LayoutParams(0, dp(54), 1f);
        c2.leftMargin = dp(8);
        c2.rightMargin = dp(8);
        LinearLayout.LayoutParams c3 = new LinearLayout.LayoutParams(0, dp(54), 1f);

        if (leftLabel != null) {
            Button lBtn = createButton(leftLabel, "#FFFFFF", "#18181B", "#D4D4D8", dp(14), 12);
            lBtn.setOnClickListener(v -> sendDirectCommand("L"));
            row.addView(lBtn, c1);
        } else {
            row.addView(new View(this), c1);
        }

        Button midBtn = createButton(
                centerLabel,
                centerDanger ? "#B91C1C" : "#FFFFFF",
                centerDanger ? "#FFFFFF" : "#18181B",
                centerDanger ? "#991B1B" : "#D4D4D8",
                dp(14),
                12
        );
        midBtn.setOnClickListener(v -> sendDirectCommand(centerCmd));
        row.addView(midBtn, c2);

        if (rightLabel != null) {
            Button rBtn = createButton(rightLabel, "#FFFFFF", "#18181B", "#D4D4D8", dp(14), 12);
            rBtn.setOnClickListener(v -> sendDirectCommand("R"));
            row.addView(rBtn, c3);
        } else {
            row.addView(new View(this), c3);
        }

        return row;
    }

    private View buildHistoryTab() {
        ScrollView scroll = new ScrollView(this);
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(dp(14), dp(14), dp(14), dp(20));

        LinearLayout headerRow = new LinearLayout(this);
        headerRow.setOrientation(LinearLayout.HORIZONTAL);
        headerRow.setGravity(Gravity.CENTER_VERTICAL);

        TextView title = new TextView(this);
        title.setText("Recent Command Cards");
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
        col.setPadding(dp(14), dp(14), dp(14), dp(20));

        LinearLayout accountCard = createCard();
        TextView aTitle = new TextView(this);
        aTitle.setText("Authenticated Administrator");
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

        Button logoutBtn = createButton("Sign Out (Revoke Sanctum Token)", "#FEF2F2", "#B91C1C", "#FECACA", dp(12), 13);
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
        settingsRobotIdInput = createInput(getRobotId(), InputType.TYPE_CLASS_TEXT);
        connCard.addView(settingsRobotIdInput, topMarginLp(dp(6)));

        connCard.addView(createFieldLabel("Laravel Sanctum API Base URL"), topMarginLp(dp(12)));
        settingsApiUrlInput = createInput(getBaseUrl(), InputType.TYPE_TEXT_VARIATION_URI);
        connCard.addView(settingsApiUrlInput, topMarginLp(dp(6)));

        connCard.addView(createFieldLabel("Direct ESP32 Robot Controller URL"), topMarginLp(dp(12)));
        settingsDirectUrlInput = createInput(getDirectUrl(), InputType.TYPE_TEXT_VARIATION_URI);
        connCard.addView(settingsDirectUrlInput, topMarginLp(dp(6)));

        Button saveBtn = createButton("Save Connection Settings", "#047857", "#FFFFFF", "#065F46", dp(12), 13);
        saveBtn.setOnClickListener(v -> {
            prefs.edit()
                    .putString(KEY_ROBOT_ID, settingsRobotIdInput.getText().toString().trim())
                    .putString(KEY_BASE_URL, settingsApiUrlInput.getText().toString().trim())
                    .putString(KEY_DIRECT_URL, settingsDirectUrlInput.getText().toString().trim())
                    .apply();
            telemetryRobotText.setText(getRobotId());
            updateHeaderSubtitle();
            showBanner("Connection settings saved.", false);
            fetchStatus();
        });
        LinearLayout.LayoutParams saveLp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(46));
        saveLp.topMargin = dp(16);
        connCard.addView(saveBtn, saveLp);

        col.addView(connCard, topMarginLp(dp(12)));
        scroll.addView(col);
        return scroll;
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
        if (isAuthenticated()) {
            loginScreen.setVisibility(View.GONE);
            mainShell.setVisibility(View.VISIBLE);
            accountNameText.setText(prefs.getString(KEY_USER_NAME, "Administrator"));
            accountEmailText.setText(prefs.getString(KEY_USER_EMAIL, "admin@example.com"));
            settingsApiUrlInput.setText(getBaseUrl());
            settingsDirectUrlInput.setText(getDirectUrl());
            settingsRobotIdInput.setText(getRobotId());
            fetchStatus();
            fetchCommandHistory(1);
        } else {
            mainShell.setVisibility(View.GONE);
            loginScreen.setVisibility(View.VISIBLE);
            loginServerInput.setText(getBaseUrl());
        }
    }

    private void performLogin() {
        emailErrorText.setVisibility(View.GONE);
        passwordErrorText.setVisibility(View.GONE);
        statusBanner.setVisibility(View.GONE);

        String serverUrl = loginServerInput.getText().toString().trim().replaceAll("/+$", "");
        String email = loginEmailInput.getText().toString().trim();
        String password = loginPasswordInput.getText().toString();

        if (!serverUrl.isEmpty()) {
            prefs.edit().putString(KEY_BASE_URL, serverUrl).apply();
            updateHeaderSubtitle();
        }

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
                prefs.edit()
                        .putString(KEY_TOKEN, json.getString("token"))
                        .putString(KEY_USER_NAME, user != null ? user.optString("name", "Administrator") : "Administrator")
                        .putString(KEY_USER_EMAIL, user != null ? user.optString("email", "") : "")
                        .apply();
                showBanner("Signed in with Sanctum token.", false);
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
            showBanner("Unexpected server response (HTTP " + res.code + ").", true);
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

    private void fetchStatus() {
        executor.execute(() -> {
            try {
                ApiResponse res = requestApi("GET", "/api/v1/mobile/status?robot_id=" + getRobotId(), null, true);
                runOnUiThread(() -> {
                    if (res.code == 401) {
                        prefs.edit().remove(KEY_TOKEN).apply();
                        refreshAuthState();
                        return;
                    }
                    try {
                        JSONObject json = new JSONObject(res.body);
                        telemetryRobotText.setText(json.optString("robot_id", getRobotId()));
                        JSONObject tel = json.optJSONObject("telemetry");
                        if (tel != null) {
                            telemetryStatusPill.setText(tel.optString("movement_status", "Active"));
                            telemetryBatteryText.setText("Battery: " + Math.round(tel.optDouble("battery_level", 0)) + "%");
                            telemetryBinsText.setText("Bins: P " + tel.optString("plastic_bin_level", "--")
                                    + " / G " + tel.optString("general_bin_level", "--"));
                            telemetryUpdatedText.setText("Updated: " + tel.optString("updated_human", "Recently"));
                        } else {
                            telemetryStatusPill.setText("No Telemetry");
                            telemetryUpdatedText.setText("Waiting for first robot telemetry log");
                        }
                    } catch (Exception ignored) {
                    }
                });
            } catch (Exception e) {
                runOnUiThread(() -> telemetryUpdatedText.setText("Offline: cannot reach " + getBaseUrl()));
            }
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
                runOnUiThread(() -> directStatusText.setText(code == 200 ? "Sent (" + cmd + ")" : "HTTP " + code));
            } catch (Exception e) {
                runOnUiThread(() -> directStatusText.setText("ESP32 AP unreachable (" + getDirectUrl() + ")"));
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
                        "/api/v1/mobile/commands?robot_id=" + getRobotId() + "&page=" + page, null, true);
                runOnUiThread(() -> {
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
        if ("completed".equalsIgnoreCase(status) || "received".equalsIgnoreCase(status)) {
            badge.setTextColor(Color.parseColor("#065F46"));
            badge.setBackground(roundedBox("#ECFDF5", "#A7F3D0", dp(99)));
        } else if ("cancelled".equalsIgnoreCase(status) || "expired".equalsIgnoreCase(status)) {
            badge.setTextColor(Color.parseColor("#991B1B"));
            badge.setBackground(roundedBox("#FEF2F2", "#FECACA", dp(99)));
        } else {
            badge.setTextColor(Color.parseColor("#92400E"));
            badge.setBackground(roundedBox("#FFFBEB", "#FDE68A", dp(99)));
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

    private boolean isAuthenticated() {
        String token = prefs.getString(KEY_TOKEN, "");
        return token != null && !token.isEmpty();
    }

    private String getBaseUrl() {
        return prefs.getString(KEY_BASE_URL, DEFAULT_BASE_URL).replaceAll("/+$", "");
    }

    private String getDirectUrl() {
        return prefs.getString(KEY_DIRECT_URL, DEFAULT_DIRECT_URL).replaceAll("/+$", "");
    }

    private String getRobotId() {
        String id = prefs.getString(KEY_ROBOT_ID, "ROBOT-001");
        return id != null && !id.isEmpty() ? id : "ROBOT-001";
    }

    private void updateHeaderSubtitle() {
        headerSubtitle.setText(getRobotId() + " • " + getBaseUrl().replaceFirst("^https?://", ""));
    }

    private void showBanner(String message, boolean isError) {
        statusBanner.setText(message);
        statusBanner.setTextColor(Color.parseColor(isError ? "#991B1B" : "#065F46"));
        statusBanner.setBackgroundColor(Color.parseColor(isError ? "#FEF2F2" : "#ECFDF5"));
        statusBanner.setVisibility(View.VISIBLE);
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

    private EditText createInput(String initial, int inputType) {
        EditText et = new EditText(this);
        et.setText(initial);
        et.setInputType(inputType);
        et.setTextColor(Color.parseColor("#18181B"));
        et.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        et.setPadding(dp(12), dp(10), dp(12), dp(10));
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
