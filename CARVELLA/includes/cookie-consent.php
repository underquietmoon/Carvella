<?php
/* includes/cookie-consent.php - Cookie consent banner partial. */
// Cookie consent banner — shown only until the visitor makes a choice.
// Choice is persisted in localStorage under the key 'carvella_cookie_consent'.
?>
<div id="cookie-consent-banner" class="cookie-banner" role="dialog" aria-live="polite" aria-label="Cookie settings">
  <div class="cookie-banner-inner">
    <div class="cookie-banner-text">
      <div>
        <p class="cookie-banner-title">We use cookies to make everything run smoothly.</p>
        <p class="cookie-banner-desc">
          We only set cookies that are strictly necessary to keep you signed in and protect your reservations.
          No ads, no cross-site tracking. You can accept or reject them, and read our
          <a href="<?= BASE_URL ?>/cookie-policy.php">Cookie Policy</a> any time.
        </p>
      </div>
    </div>
    <div class="cookie-banner-actions">
      <button type="button" class="cookie-btn cookie-btn-accept" data-cookie-choice="accepted">
        Accept Cookies
      </button>
      <button type="button" class="cookie-btn cookie-btn-decline" data-cookie-choice="declined">
        Reject
      </button>
    </div>
    <button type="button" class="cookie-banner-close" data-cookie-close aria-label="Dismiss cookie notice for now">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>
</div>
<script>
(function () {
    var KEY = 'carvella_cookie_consent';
    var banner = document.getElementById('cookie-consent-banner');

    function readPreference() {
        try {
            return localStorage.getItem(KEY);
        } catch (e) {
            return null;
        }
    }

    function writePreference(choice) {
        try {
            localStorage.setItem(KEY, choice);
        } catch (e) {
            /* Storage unavailable — just hide the banner for this session. */
        }
    }

    function hideBanner() {
        if (banner) {
            banner.classList.add('cookie-banner-hidden');
            setTimeout(function () { if (banner) banner.remove(); }, 320);
        }
    }

    function choose(choice) {
        writePreference(choice);
        hideBanner();
    }

    if (!banner) return;

    /* A recorded choice must actually suppress the banner. Without this
       guard the Accept/Reject buttons wrote to localStorage and hid the
       panel, but nothing ever read the value back, so the banner came
       straight back on the next page load and every choice was
       effectively write-only. */
    if (readPreference()) {
        banner.remove();
        return;
    }

    banner.querySelectorAll('[data-cookie-choice]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            choose(btn.getAttribute('data-cookie-choice'));
        });
    });
    var closeBtn = banner.querySelector('[data-cookie-close]');
    if (closeBtn) {
        /* Dismissal is NOT a decision. Writing 'declined' here recorded a
           rejection the visitor never made, and with the guard above a
           single stray click of the × would have locked that choice in
           permanently. Hide for this page view only and ask again later. */
        closeBtn.addEventListener('click', hideBanner);
    }
})();
</script>
<style>
    .cookie-banner {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        background: rgba(255, 247, 255, 0.42);
        -webkit-backdrop-filter: blur(22px) saturate(170%);
        backdrop-filter: blur(22px) saturate(170%);
        border-top: 1px solid rgba(255, 255, 255, 0.55);
        box-shadow: 0 -20px 60px -12px rgba(46, 38, 56, 0.28);
        opacity: 1;
        transform: translateY(0);
        transition: opacity 0.35s ease, transform 0.35s ease;
    }
    .cookie-banner::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(160deg, rgba(255,255,255,0.38) 0%, rgba(255,255,255,0.08) 60%, rgba(232,221,255,0.12) 100%);
        pointer-events: none;
    }
    .cookie-banner-inner {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
        gap: 14px;
        padding: 22px 28px;
        max-width: 1100px;
        margin: 0 auto;
        z-index: 1;
    }
    .cookie-banner-hidden {
        opacity: 0;
        transform: translateY(100%);
        pointer-events: none;
    }
    .cookie-banner-text {
        display: flex;
        align-items: flex-start;
        justify-content: flex-start;
    }
    .cookie-banner-icon {
        font-size: 30px;
        color: #6b38d4;
        flex-shrink: 0;
        margin-top: 0;
    }
    .cookie-banner-title {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: 16px;
        font-weight: 700;
        line-height: 24px;
        color: #201829;
        margin: 0 0 4px;
    }
    .cookie-banner-desc {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: 14px;
        font-weight: 400;
        line-height: 22px;
        color: #494454;
        margin: 0;
    }
    .cookie-banner-desc a {
        color: #5516be;
        font-weight: 600;
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    .cookie-banner-desc a:hover {
        color: #6b38d4;
    }
    .cookie-banner-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }
    .cookie-btn {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: 12px;
        font-weight: 700;
        line-height: 18px;
        letter-spacing: 0.01em;
        padding: 8px 16px;
        border-radius: 9999px;
        border: 1px solid transparent;
        cursor: pointer;
        transition: background-color 0.2s ease, color 0.2s ease, transform 0.1s ease;
        -webkit-tap-highlight-color: transparent;
    }
    .cookie-btn:active {
        transform: scale(0.97);
    }
    .cookie-btn-accept {
        background: #6b38d4;
        color: #ffffff;
        box-shadow: 0 6px 16px -4px rgba(107, 56, 212, 0.5);
    }
    .cookie-btn-accept:hover {
        background: #712edd;
    }
    .cookie-btn-decline {
        background: rgba(255, 255, 255, 0.7);
        color: #494454;
        border-color: rgba(203, 195, 215, 0.5);
    }
    .cookie-btn-decline:hover {
        background: rgba(255, 255, 255, 0.95);
        color: #201829;
    }
    .cookie-banner-close {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 9999px;
        background: rgba(255, 255, 255, 0.5);
        color: #7b7486;
        cursor: pointer;
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    .cookie-banner-close:hover {
        background: rgba(255, 255, 255, 0.85);
        color: #201829;
    }
    @media (min-width: 768px) {
        .cookie-banner-inner {
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            padding: 24px 32px;
        }
        .cookie-banner-text {
            flex: 1;
            max-width: 720px;
        }
        .cookie-banner-actions {
            flex-shrink: 0;
        }
    }
</style>
<?php /* End of includes/cookie-consent.php */ ?>
