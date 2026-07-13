const header = document.querySelector(".site-header");
const toggle = document.querySelector(".nav-toggle");
const nav = document.querySelector(".site-nav");
const contactStatus = document.querySelector("#contact-status");

function updateHeader() {
  header.classList.toggle("is-scrolled", window.scrollY > 18);
}

toggle.addEventListener("click", () => {
  const isOpen = toggle.getAttribute("aria-expanded") === "true";
  toggle.setAttribute("aria-expanded", String(!isOpen));
  header.classList.toggle("is-open", !isOpen);
});

nav.addEventListener("click", (event) => {
  if (event.target instanceof HTMLAnchorElement) {
    toggle.setAttribute("aria-expanded", "false");
    header.classList.remove("is-open");
  }
});

const reduceMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
const carouselToggles = document.querySelectorAll("[data-carousel-toggle]");

carouselToggles.forEach((button) => {
  const carousel = button.closest(".hero-carousel, .project-hero-carousel");
  const track = carousel?.querySelector(".hero-carousel-track, .project-screen-track");
  const label = button.querySelector("[data-carousel-label]");
  const icon = button.querySelector(".carousel-toggle-icon");

  if (!carousel || !track || !label || !icon) {
    return;
  }

  let manualPaused = false;
  let pointerInside = false;
  let focusInside = false;
  let userChangedState = false;

  const renderCarouselState = () => {
    const reducedMotion = reduceMotionQuery.matches;
    const paused = reducedMotion || manualPaused || pointerInside || focusInside;

    track.classList.toggle("is-paused", paused);
    button.disabled = reducedMotion;
    button.setAttribute("aria-pressed", String(reducedMotion || manualPaused));

    if (reducedMotion) {
      label.textContent = "Animatie uit";
      icon.textContent = "■";
      button.setAttribute(
        "aria-label",
        "Animatie uitgeschakeld door voorkeur voor minder beweging"
      );
    } else if (manualPaused) {
      label.textContent = "Afspelen";
      icon.textContent = "▶";
      button.setAttribute("aria-label", "Animatie afspelen");
    } else {
      label.textContent = "Pauzeren";
      icon.textContent = "Ⅱ";
      button.setAttribute("aria-label", "Animatie pauzeren");
    }
  };

  button.addEventListener("click", () => {
    userChangedState = true;
    manualPaused = !manualPaused;
    renderCarouselState();
  });

  carousel.addEventListener("pointerenter", () => {
    pointerInside = true;
    renderCarouselState();
  });

  carousel.addEventListener("pointerleave", () => {
    pointerInside = false;
    renderCarouselState();
  });

  carousel.addEventListener("focusin", () => {
    focusInside = true;
    renderCarouselState();
  });

  carousel.addEventListener("focusout", (event) => {
    if (!carousel.contains(event.relatedTarget)) {
      focusInside = false;
      renderCarouselState();
    }
  });

  const handleMotionPreference = () => {
    if (!userChangedState && !reduceMotionQuery.matches) {
      manualPaused = false;
    }
    renderCarouselState();
  };

  if (typeof reduceMotionQuery.addEventListener === "function") {
    reduceMotionQuery.addEventListener("change", handleMotionPreference);
  } else {
    reduceMotionQuery.addListener(handleMotionPreference);
  }

  renderCarouselState();
});

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
