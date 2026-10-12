const header = document.querySelector(".site-header");
const toggle = document.querySelector(".nav-toggle");
const nav = document.querySelector(".site-nav");
const contactStatus = document.querySelector("#contact-status");

function updateHeader() {
  if (!(header instanceof HTMLElement)) {
    return;
  }

  header.classList.toggle("is-scrolled", window.scrollY > 18);
}

if (toggle instanceof HTMLElement && header instanceof HTMLElement) {
  toggle.addEventListener("click", () => {
    const isOpen = toggle.getAttribute("aria-expanded") === "true";
    toggle.setAttribute("aria-expanded", String(!isOpen));
    toggle.setAttribute("aria-label", isOpen ? "Menu openen" : "Menu sluiten");
    header.classList.toggle("is-open", !isOpen);
  });
}

if (nav instanceof HTMLElement && toggle instanceof HTMLElement && header instanceof HTMLElement) {
  nav.addEventListener("click", (event) => {
    if (event.target instanceof HTMLAnchorElement) {
      toggle.setAttribute("aria-expanded", "false");
      toggle.setAttribute("aria-label", "Menu openen");
      header.classList.remove("is-open");
    }
  });
}

const reduceMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");

const revealCards = document.querySelectorAll(".reveal-card");

if ("IntersectionObserver" in window) {
  const revealObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          revealObserver.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.16 }
  );

  revealCards.forEach((card) => revealObserver.observe(card));
} else {
  revealCards.forEach((card) => card.classList.add("is-visible"));
}

updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });

document.querySelectorAll("[data-opname-calculator]").forEach((calculator) => {
  const input = calculator.querySelector('input[type="range"]');
  const requestCount = calculator.querySelector("[data-request-count]");
  const monthlyHours = calculator.querySelector("[data-monthly-hours]");
  const yearlyHours = calculator.querySelector("[data-yearly-hours]");
  const yearlyDays = calculator.querySelector("[data-yearly-days]");
  const monthlyValue = calculator.querySelector("[data-monthly-value]");
  const yearlyValue = calculator.querySelector("[data-yearly-value]");

  if (!(input instanceof HTMLInputElement)) {
    return;
  }

  const avoidableShare = Number(calculator.dataset.avoidableShare) || 0.9;
  const travelMinutes = Number(calculator.dataset.travelMinutes) || 60;
  const hourlyRate = Number(calculator.dataset.hourlyRate) || 70;
  const numberFormatter = new Intl.NumberFormat("nl-NL", { maximumFractionDigits: 1 });
  const currencyFormatter = new Intl.NumberFormat("nl-NL", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  });

  const updateCalculator = () => {
    const requests = Number(input.value);
    const hoursPerMonth = (requests * avoidableShare * travelMinutes) / 60;
    const hoursPerYear = hoursPerMonth * 12;
    const daysPerYear = hoursPerYear / 8;
    const range = Number(input.max) - Number(input.min);
    const progress = range > 0 ? ((requests - Number(input.min)) / range) * 100 : 0;

    if (requestCount instanceof HTMLOutputElement) {
      requestCount.value = numberFormatter.format(requests);
    }

    input.style.setProperty("--range-progress", `${progress}%`);

    if (monthlyHours) monthlyHours.textContent = numberFormatter.format(hoursPerMonth);
    if (yearlyHours) yearlyHours.textContent = numberFormatter.format(hoursPerYear);
    if (yearlyDays) yearlyDays.textContent = numberFormatter.format(daysPerYear);
    if (monthlyValue) monthlyValue.textContent = currencyFormatter.format(hoursPerMonth * hourlyRate);
    if (yearlyValue) yearlyValue.textContent = currencyFormatter.format(hoursPerYear * hourlyRate);
  };

  input.addEventListener("input", updateCalculator);
  updateCalculator();
});

if (contactStatus) {
  const contactResult = new URLSearchParams(window.location.search).get("contact");
  const contactForm = contactStatus.closest(".contact-form");
  const messages = {
    invalid: "Controleer de ingevulde gegevens en probeer het opnieuw.",
    wait: "Wacht even voordat je het formulier opnieuw verstuurt.",
    error: "Verzenden is niet gelukt. Mail je vraag naar info@jpwebcreation.nl.",
  };

  const restoreContactState = async () => {
    if (!(contactForm instanceof HTMLFormElement)) {
      return;
    }

    try {
      const response = await fetch("/contact.php?action=state", {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });

      if (!response.ok) {
        return;
      }

      const state = await response.json();
      const values = state?.values ?? {};
      const errors = state?.errors ?? {};

      Object.entries(values).forEach(([name, value]) => {
        const field = contactForm.elements.namedItem(name);

        if (
          (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)
          && typeof value === "string"
          && field.type !== "checkbox"
        ) {
          field.value = value;
        }
      });

      let firstInvalidField = null;

      Object.entries(errors).forEach(([name, message]) => {
        const field = contactForm.elements.namedItem(name);
        const error = document.querySelector(`#contact-${name}-error`);

        if (
          !(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)
          || !(error instanceof HTMLElement)
          || typeof message !== "string"
        ) {
          return;
        }

        error.textContent = message;
        error.hidden = false;
        field.setAttribute("aria-invalid", "true");
        field.setAttribute("aria-describedby", error.id);
        firstInvalidField ??= field;
      });

      if (firstInvalidField) {
        window.requestAnimationFrame(() => {
          firstInvalidField.scrollIntoView({
            block: "center",
            behavior: reduceMotionQuery.matches ? "auto" : "smooth",
          });
          firstInvalidField.focus({ preventScroll: true });
        });
      }
    } catch {
      // De algemene foutmelding blijft zichtbaar als herstel niet beschikbaar is.
    }
  };

  if (contactResult === "success") {
    contactStatus.innerHTML = `
      <span class="form-status-icon" aria-hidden="true">✓</span>
      <span class="form-status-kicker">Bericht verzonden</span>
      <strong>Bedankt, ik heb je bericht ontvangen.</strong>
      <span>Ik reageer binnen één werkdag. Je hoeft niets meer te doen.</span>
    `;
    contactStatus.classList.add("is-success");
    contactStatus.setAttribute("tabindex", "-1");
    contactStatus.hidden = false;
    contactForm?.classList.add("has-success");

    window.requestAnimationFrame(() => {
      const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

      contactStatus.scrollIntoView({
        block: "center",
        behavior: reduceMotion ? "auto" : "smooth",
      });
      contactStatus.focus({ preventScroll: true });
    });
  } else if (contactResult && messages[contactResult]) {
    contactStatus.textContent = messages[contactResult];
    contactStatus.classList.add("is-error");
    contactStatus.hidden = false;

    window.requestAnimationFrame(() => {
      contactStatus.scrollIntoView({
        block: "center",
        behavior: reduceMotionQuery.matches ? "auto" : "smooth",
      });
    });

    if (contactResult === "invalid") {
      void restoreContactState();
    }
  }
}

// Stunten met Plinten example: feature flag default OFF until written permission.
(() => {
  const page = document.querySelector(".geo-page");
  if (!(page instanceof HTMLElement)) {
    return;
  }

  const enabled = page.dataset.stuntenExample === "on";
  const question = document.querySelector('[data-stunten-variant="question"]');
  const live = document.querySelector('[data-stunten-variant="live"]');

  if (question instanceof HTMLElement) {
    question.hidden = enabled;
  }

  if (live instanceof HTMLElement) {
    live.hidden = !enabled;
  }
})();

const scanStatus = document.querySelector("#scan-status");
const scanForm = document.querySelector(".geo-scan-form");

if (scanStatus instanceof HTMLElement && scanForm instanceof HTMLFormElement) {
  const scanMessages = {
    invalid: "Controleer de ingevulde gegevens en probeer het opnieuw.",
    rate_limit: "Je hebt het formulier een paar keer achter elkaar verstuurd. Probeer het over een uur opnieuw.",
    already_scanned: "Voor deze website of dit e-mailadres is al een gratis scan gedaan. We sturen een inloglink naar het e-mailadres van die scan.",
    disposable_email: "Gebruik een e-mailadres dat je blijft lezen. Je inloggegevens en uitslag komen daar terecht.",
    daily_limit: "Vandaag zijn alle gratis scans bezet. Bevestig je adres; je scan start dan automatisch op de eerstvolgende dag met ruimte. Je krijgt een mail.",
    blocked_website: "We mogen deze website niet lezen. Daardoor kunnen we geen goede vragen maken. Neem contact op als je denkt dat dit niet klopt.",
    error: "Verzenden is niet gelukt. Mail je vraag naar info@jpwebcreation.nl.",
  };

  const clearScanErrors = () => {
    scanForm.querySelectorAll(".field-error").forEach((error) => {
      if (error instanceof HTMLElement) {
        error.textContent = "";
        error.hidden = true;
      }
    });

    scanForm.querySelectorAll("[aria-invalid]").forEach((field) => {
      field.removeAttribute("aria-invalid");
      field.removeAttribute("aria-describedby");
    });
  };

  const applyScanFieldErrors = (errors) => {
    let firstInvalidField = null;

    Object.entries(errors ?? {}).forEach(([name, message]) => {
      const field = scanForm.elements.namedItem(name);
      const error = document.querySelector(`#scan-${name}-error`);

      if (
        !(field instanceof HTMLInputElement)
        || !(error instanceof HTMLElement)
        || typeof message !== "string"
      ) {
        return;
      }

      error.textContent = message;
      error.hidden = false;
      field.setAttribute("aria-invalid", "true");
      field.setAttribute("aria-describedby", error.id);
      firstInvalidField ??= field;
    });

    if (firstInvalidField) {
      window.requestAnimationFrame(() => {
        firstInvalidField.scrollIntoView({
          block: "center",
          behavior: reduceMotionQuery.matches ? "auto" : "smooth",
        });
        firstInvalidField.focus({ preventScroll: true });
      });
    }
  };

  const showScanSuccess = (message) => {
    const emailField = scanForm.elements.namedItem("email");
    const email = emailField instanceof HTMLInputElement ? emailField.value.trim() : "";
    const text = typeof message === "string" && message
      ? message
      : `Check je mail. We hebben een bevestigingslink gestuurd naar ${email || "je adres"}. Zie je niets? Kijk in je spam of stuur opnieuw.`;

    scanStatus.innerHTML = `
      <span class="form-status-icon" aria-hidden="true">✓</span>
      <span class="form-status-kicker">Aanvraag ontvangen</span>
      <strong>Check je mail.</strong>
      <span></span>
    `;
    const detail = scanStatus.querySelector("span:last-child");
    if (detail instanceof HTMLElement) {
      detail.textContent = text.replace(/^Check je mail\.\s*/, "");
    }

    scanStatus.classList.remove("is-error");
    scanStatus.classList.add("is-success");
    scanStatus.setAttribute("tabindex", "-1");
    scanStatus.hidden = false;
    scanForm.classList.add("has-success");

    window.requestAnimationFrame(() => {
      scanStatus.scrollIntoView({
        block: "center",
        behavior: reduceMotionQuery.matches ? "auto" : "smooth",
      });
      scanStatus.focus({ preventScroll: true });
    });
  };

  const showScanError = (message) => {
    scanStatus.textContent = message;
    scanStatus.classList.remove("is-success");
    scanStatus.classList.add("is-error");
    scanStatus.hidden = false;

    window.requestAnimationFrame(() => {
      scanStatus.scrollIntoView({
        block: "center",
        behavior: reduceMotionQuery.matches ? "auto" : "smooth",
      });
    });
  };

  const restoreScanState = async () => {
    try {
      const response = await fetch("/ai-scan.php?action=state", {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });

      if (!response.ok) {
        return;
      }

      const state = await response.json();
      const values = state?.values ?? {};
      const errors = state?.errors ?? {};

      Object.entries(values).forEach(([name, value]) => {
        const field = scanForm.elements.namedItem(name);

        if (
          field instanceof HTMLInputElement
          && typeof value === "string"
          && field.type !== "checkbox"
        ) {
          field.value = value;
        }
      });

      applyScanFieldErrors(errors);
    } catch {
      // Algemene foutmelding blijft zichtbaar.
    }
  };

  const scanResult = new URLSearchParams(window.location.search).get("scan");

  if (scanResult === "success") {
    showScanSuccess();
  } else if (scanResult && scanMessages[scanResult]) {
    showScanError(scanMessages[scanResult]);

    if (scanResult === "invalid") {
      void restoreScanState();
    }
  }

  scanForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearScanErrors();
    scanStatus.hidden = true;
    scanStatus.classList.remove("is-error", "is-success");

    const submitButton = scanForm.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) {
      submitButton.disabled = true;
    }

    try {
      const response = await fetch("/ai-scan.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: new FormData(scanForm),
      });

      const payload = await response.json().catch(() => null);

      if (payload?.ok) {
        showScanSuccess(payload.message);
        return;
      }

      if (payload?.errors && Object.keys(payload.errors).length > 0) {
        Object.entries(payload.values ?? {}).forEach(([name, value]) => {
          const field = scanForm.elements.namedItem(name);

          if (
            field instanceof HTMLInputElement
            && typeof value === "string"
            && field.type !== "checkbox"
          ) {
            field.value = value;
          }
        });

        showScanError(payload.message || scanMessages.invalid);
        applyScanFieldErrors(payload.errors);
        return;
      }

      const status = payload?.status;
      showScanError(
        (status && scanMessages[status])
          || payload?.message
          || scanMessages.error
      );
    } catch {
      showScanError(scanMessages.error);
    } finally {
      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = false;
      }
    }
  });
}
