const header = document.querySelector(".site-header");
const toggle = document.querySelector(".nav-toggle");
const nav = document.querySelector(".site-nav");

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

updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });
