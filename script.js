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
    success: {
      text: "Bedankt. Je bericht is verzonden.",
      className: "is-success",
    },
    invalid: {
      text: "Controleer de ingevulde gegevens en probeer het opnieuw.",
      className: "is-error",
    },
    wait: {
      text: "Wacht even voordat je het formulier opnieuw verstuurt.",
      className: "is-error",
    },
    error: {
      text: "Verzenden is niet gelukt. Mail je vraag naar info@jpwebcreation.nl.",
      className: "is-error",
    },
  };

  if (contactResult && messages[contactResult]) {
    contactStatus.textContent = messages[contactResult].text;
    contactStatus.classList.add(messages[contactResult].className);
    contactStatus.hidden = false;

    window.requestAnimationFrame(() => {
      contactStatus.scrollIntoView({ block: "center", behavior: "auto" });
    });
  }
}
