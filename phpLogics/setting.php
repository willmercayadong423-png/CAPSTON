<?php /* ── HEHMS Settings Modal — <?php include("settings-modal.php"); ?> before </body> ── */ ?>

<div id="settings-modal" onclick="closeSettings(event)">
    <div class="settings-box">

        <div class="settings-header">
            <h3>⚙️ Settings</h3>
            <button class="settings-close" onclick="closeSettings()">✕</button>
        </div>

        <div class="settings-body">

            <p class="settings-section-label">Appearance</p>

            <div class="settings-row">
                <div class="settings-row-info">
                    <strong>Dark Mode</strong>
                    <span>Switch between light and dark theme</span>
                </div>
                <div onclick="toggleDarkMode()" style="cursor:pointer;">
    <span>Dark Mode</span>
    <div id="dm-track" style="width:50px;height:24px;background:#ccc;border-radius:20px;position:relative;">
        <div id="dm-knob" style="width:20px;height:20px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;"></div>
    </div>
    <span id="dm-label">Off</span>
</div>
            </div>

        </div>

        <div class="settings-footer">
            <p>Settings are saved automatically to your browser.</p>
        </div>

    </div>
</div>