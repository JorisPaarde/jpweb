(() => {
  // Stunten live example: only when flag is on AND /assets/data/stunten-voorbeeld.json exists.
  // That file is absent by default (no real scores in source until permission).
  const page = document.querySelector(".geo-page");
  const question = document.querySelector('[data-stunten-variant="question"]');
  const live = document.querySelector('[data-stunten-variant="live"]');

  const renderStuntenExample = (data) => {
    if (!(live instanceof HTMLElement) || !(question instanceof HTMLElement)) {
      return;
    }

    const pct = typeof data.pct === "number" ? `${data.pct}%` : String(data.pct ?? "");
    const badgeClass = data.badge_class === "is-mid" || data.badge_class === "is-weak"
      ? data.badge_class
      : "is-good";

    live.innerHTML = `
      <article class="geo-score-card" aria-label="Voorbeeldmeting">
        <header class="geo-score-card-head">
          <div>
            <strong></strong>
            <span></span>
          </div>
          <span class="geo-score-measured"></span>
        </header>
        <div class="geo-score-main">
          <p class="geo-score-pct"></p>
          <p class="geo-score-badge ${badgeClass}"></p>
        </div>
        <p class="geo-score-band"></p>
        <p class="geo-score-trend"></p>
        <p class="geo-score-foot"></p>
      </article>
      <p class="geo-example-note"></p>
      <p><a class="button primary" href="#scan">Doe de gratis scan</a></p>
    `;

    const setText = (selector, value) => {
      const node = live.querySelector(selector);
      if (node instanceof HTMLElement && typeof value === "string") {
        node.textContent = value;
      }
    };

    setText(".geo-score-card-head strong", data.name ?? "");
    setText(".geo-score-card-head span", data.domain ?? "");
    setText(".geo-score-measured", data.measured_label ?? "");
    setText(".geo-score-pct", pct);
    setText(".geo-score-badge", data.badge ?? "");
    setText(".geo-score-band", data.band ?? "");
    setText(".geo-score-trend", data.trend ?? "");
    setText(".geo-score-foot", data.foot ?? "");
    setText(".geo-example-note", data.note ?? "");

    question.hidden = true;
    live.hidden = false;
  };

  if (
    page instanceof HTMLElement
    && page.dataset.stuntenExample === "on"
    && live instanceof HTMLElement
  ) {
    fetch("/assets/data/stunten-voorbeeld.json", { credentials: "same-origin" })
      .then((response) => {
        if (!response.ok) {
          throw new Error("stunten voorbeeld absent");
        }

        return response.json();
      })
      .then(renderStuntenExample)
      .catch(() => {
        // Flag on but no data file: keep the question variant.
        live.hidden = true;
        live.replaceChildren();
        if (question instanceof HTMLElement) {
          question.hidden = false;
        }
      });
  } else if (live instanceof HTMLElement) {
    live.hidden = true;
    live.replaceChildren();
  }
})();

(() => {
  const root = document.querySelector("[data-pricing-root]");
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const euro = new Intl.NumberFormat("nl-NL", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  });

  const interactive = root.querySelector("[data-pricing-interactive]");
  const fallback = root.querySelector("[data-pricing-fallback]");
  const range = root.querySelector("#pricing-questions");
  const rangeOutput = root.querySelector("#pricing-questions-value");
  const monthlyEl = root.querySelector("[data-price-monthly]");
  const weeklyEl = root.querySelector("[data-price-weekly]");
  const annualEl = root.querySelector("[data-price-annual]");
  const actionsEl = root.querySelector("[data-price-actions]");
  const contactEl = root.querySelector("[data-price-contact]");
  const compleetEl = root.querySelector("[data-price-compleet]");
  const exampleEl = root.querySelector("[data-pricing-example]");
  const executeEl = root.querySelector("[data-execute-line]");
  const compleetNoteEl = root.querySelector("[data-compleet-note]");
  const vatNodes = root.querySelectorAll("[data-vat-note]");
  const hourInputs = root.querySelectorAll('input[name="compleet-hours"]');

  const formatEuro = (amount) => euro.format(amount);

  const selectedHours = () => {
    const checked = root.querySelector('input[name="compleet-hours"]:checked');
    return checked instanceof HTMLInputElement ? checked.value : "3";
  };

  const update = (config) => {
    if (!(range instanceof HTMLInputElement)) {
      return;
    }

    const tiers = config.tiers ?? [];
    const index = Number(range.value);
    const aboveIndex = tiers.length;
    const isAbove = index >= aboveIndex;
    const tier = isAbove ? tiers[tiers.length - 1] : tiers[index];

    if (!tier) {
      return;
    }

    const requestLabel = config.above_max?.request_label ?? "op aanvraag";

    const questionsLabel = isAbove ? "1000+ vragen" : `${tier.questions} vragen`;
    const hours = selectedHours();
    const compleet = isAbove ? null : tier.compleet?.[hours];
    const valueText = isAbove
      ? `${questionsLabel}, ${requestLabel}`
      : `${tier.questions} vragen, ${formatEuro(tier.monthly)} per maand`;

    range.setAttribute("aria-valuenow", String(isAbove ? 1000 : tier.questions));
    range.setAttribute("aria-valuetext", valueText);

    if (rangeOutput instanceof HTMLElement) {
      rangeOutput.textContent = questionsLabel;
    }

    if (monthlyEl instanceof HTMLElement) {
      monthlyEl.textContent = isAbove ? requestLabel : formatEuro(tier.monthly);
    }

    if (weeklyEl instanceof HTMLElement) {
      weeklyEl.textContent = isAbove ? "—" : `${formatEuro(tier.weekly)} per week`;
    }

    if (annualEl instanceof HTMLElement) {
      annualEl.textContent = isAbove
        ? "—"
        : `${formatEuro(tier.annual)} per jaar (10 maanden)`;
    }

    if (actionsEl instanceof HTMLElement) {
      actionsEl.textContent = isAbove ? "—" : String(tier.actions);
    }

    if (contactEl instanceof HTMLElement) {
      if (isAbove) {
        contactEl.hidden = false;
        contactEl.textContent = requestLabel;
      } else {
        contactEl.hidden = true;
        contactEl.textContent = "";
      }
    }

    if (compleetEl instanceof HTMLElement) {
      compleetEl.textContent = typeof compleet === "number"
        ? formatEuro(compleet)
        : requestLabel;
    }
  };

  const wire = (config) => {
    if (exampleEl instanceof HTMLElement && config.example?.label) {
      exampleEl.textContent = config.example.label;
    }

    if (executeEl instanceof HTMLElement && config.execute_line) {
      executeEl.textContent = config.execute_line;
    }

    if (compleetNoteEl instanceof HTMLElement) {
      compleetNoteEl.textContent = [
        config.compleet_note,
        config.extra_hours_note,
      ].filter(Boolean).join(" ");
    }

    vatNodes.forEach((node) => {
      if (node instanceof HTMLElement && config.vat_note) {
        node.textContent = config.vat_note;
      }
    });

    if (range instanceof HTMLInputElement) {
      range.max = String((config.tiers?.length ?? 11));
      const defaultIndex = (config.tiers ?? []).findIndex((tier) => tier.questions === 100);
      range.value = String(defaultIndex >= 0 ? defaultIndex : 3);
      range.addEventListener("input", () => update(config));
    }

    hourInputs.forEach((input) => {
      input.addEventListener("change", () => update(config));
    });

    if (interactive instanceof HTMLElement) {
      interactive.hidden = false;
    }

    if (fallback instanceof HTMLElement) {
      fallback.hidden = true;
    }

    update(config);
  };

  fetch("/assets/data/tiers.json", { credentials: "same-origin" })
    .then((response) => {
      if (!response.ok) {
        throw new Error("tiers.json missing");
      }

      return response.json();
    })
    .then(wire)
    .catch(() => {
      // No fallback amounts in JS: show the static table from index.html.
      if (interactive instanceof HTMLElement) {
        interactive.hidden = true;
      }

      if (fallback instanceof HTMLElement) {
        fallback.hidden = false;
      }
    });
})();
