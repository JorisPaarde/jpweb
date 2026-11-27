/* Cookiekeuze (Consent Mode v2) en conversie-events voor Google Tag Manager.
   De standaardkeuze (alles geweigerd) en het terugzetten van een eerdere keuze
   staan inline in de <head>, vóór het GTM-fragment. Dit bestand toont de banner,
   bewaart de keuze en duwt alleen echte acties naar de dataLayer. */
(function () {
  "use strict";

  var STORAGE_KEY = "jpwc_consent";
  var MAX_AGE_MS = 365 * 24 * 60 * 60 * 1000;

  window.dataLayer = window.dataLayer || [];
  var gtag = window.gtag || function () { window.dataLayer.push(arguments); };

  function readChoice() {
    try {
      var c = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || "null");
      if (c && c.v === 1 && Date.now() - c.ts < MAX_AGE_MS) {
        return c;
      }
    } catch (e) {}
    return null;
  }

  function consentState(choice) {
    var ads = choice.ads ? "granted" : "denied";
    return {
      analytics_storage: choice.analytics ? "granted" : "denied",
      ad_storage: ads,
      ad_user_data: ads,
      ad_personalization: ads
    };
  }

  function saveChoice(analytics, ads) {
    var choice = { v: 1, analytics: !!analytics, ads: !!ads, ts: Date.now() };
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(choice));
    } catch (e) {}
    gtag("consent", "update", consentState(choice));
    window.dataLayer.push({
      event: "consent_update",
      consent_analytics: choice.analytics ? "granted" : "denied",
      consent_ads: choice.ads ? "granted" : "denied"
    });
    return choice;
  }

  /* Conversie: alleen na een geslaagde verzending via /contact.php (303 naar ?contact=success). */
  if (new URLSearchParams(window.location.search).get("contact") === "success") {
    window.dataLayer.push({ event: "contact_form_success", form_page: window.location.pathname });
  }

  /* Conversie: klik op een tel:-link. Het bellen zelf gaat gewoon door. */
  document.addEventListener("click", function (event) {
    var link = event.target instanceof Element ? event.target.closest('a[href^="tel:"]') : null;
    if (link) {
      window.dataLayer.push({ event: "phone_click", link_url: link.getAttribute("href") });
    }
  }, true);

  var banner = null;

  function buildBanner() {
    var el = document.createElement("section");
    el.className = "cookie-banner";
    el.setAttribute("aria-label", "Cookie-instellingen");
    el.innerHTML =
      '<p class="cookie-banner-text">Mag ik meten hoe je deze site gebruikt en mijn advertenties daarop afstemmen? ' +
      'Noodzakelijke cookies staan altijd aan. Meer in de <a href="/privacyverklaring/#cookies">privacyverklaring</a>.</p>' +
      '<div class="cookie-banner-options" hidden>' +
        '<label class="cookie-option"><input type="checkbox" checked disabled>' +
          '<span><strong>Noodzakelijk</strong> Onthoudt je keuze en laat de site werken. Staat altijd aan.</span></label>' +
        '<label class="cookie-option"><input type="checkbox" name="analytics">' +
          '<span><strong>Meten</strong> Ik zie welke pagina\'s bezocht worden en waar bezoekers afhaken (Google Analytics).</span></label>' +
        '<label class="cookie-option"><input type="checkbox" name="ads">' +
          '<span><strong>Advertenties</strong> Google Ads mag meten of iemand na een advertentie contact opneemt en advertenties daarop afstemmen.</span></label>' +
      '</div>' +
      '<div class="cookie-banner-actions">' +
        '<button type="button" class="cookie-button" data-consent="accept">Akkoord</button>' +
        '<button type="button" class="cookie-button" data-consent="reject">Weigeren</button>' +
        '<button type="button" class="cookie-button cookie-button-save" data-consent="save" hidden>Keuze opslaan</button>' +
        '<button type="button" class="cookie-link" data-consent="customize" aria-expanded="false">Zelf kiezen</button>' +
      '</div>';

    el.addEventListener("click", function (event) {
      var button = event.target instanceof Element ? event.target.closest("[data-consent]") : null;
      if (!button) {
        return;
      }
      var action = button.getAttribute("data-consent");
      if (action === "accept") {
        saveChoice(true, true);
        hideBanner();
      } else if (action === "reject") {
        saveChoice(false, false);
        hideBanner();
      } else if (action === "save") {
        saveChoice(el.querySelector('input[name="analytics"]').checked, el.querySelector('input[name="ads"]').checked);
        hideBanner();
      } else if (action === "customize") {
        showOptions(true);
      }
    });
    document.body.appendChild(el);
    return el;
  }

  function showOptions(open) {
    banner.querySelector(".cookie-banner-options").hidden = !open;
    banner.querySelector('[data-consent="save"]').hidden = !open;
    var customize = banner.querySelector('[data-consent="customize"]');
    customize.hidden = open;
    customize.setAttribute("aria-expanded", String(open));
    banner.classList.toggle("is-expanded", open);
  }

  function showBanner(withOptions) {
    if (!banner) {
      banner = buildBanner();
    }
    var current = readChoice();
    banner.querySelector('input[name="analytics"]').checked = !!(current && current.analytics);
    banner.querySelector('input[name="ads"]').checked = !!(current && current.ads);
    showOptions(!!withOptions);
    banner.hidden = false;
    if (withOptions) {
      banner.querySelector('input[name="analytics"]').focus();
    }
  }

  function hideBanner() {
    if (banner) {
      banner.hidden = true;
    }
  }

  document.addEventListener("click", function (event) {
    var trigger = event.target instanceof Element ? event.target.closest("[data-cookie-settings]") : null;
    if (trigger) {
      event.preventDefault();
      showBanner(true);
    }
  });

  if (!readChoice()) {
    showBanner(false);
  }
})();
