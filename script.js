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
  const messages = {
    invalid: "Controleer de ingevulde gegevens en probeer het opnieuw.",
    wait: "Wacht even voordat je het formulier opnieuw verstuurt.",
    error: "Verzenden is niet gelukt. Mail je vraag naar info@jpwebcreation.nl.",
  };

  if (contactResult === "success") {
    const contactForm = contactStatus.closest(".contact-form");

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
      const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

      contactStatus.scrollIntoView({
        block: "center",
        behavior: reduceMotion ? "auto" : "smooth",
      });
    });
  }
}
