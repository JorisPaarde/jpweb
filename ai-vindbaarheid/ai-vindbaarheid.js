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

    let monthly = tier.monthly;
    let questionsLabel = `${tier.questions} vragen`;
    let contactText = "";

    if (isAbove) {
      const per100 = config.above_max?.per_started_100 ?? 89;
      // Far end: show calculated first step beyond 1000 + contact.
      const startedHundreds = 1;
      monthly = tier.monthly + per100 * startedHundreds;
      questionsLabel = "1000+ vragen";
      contactText = `${formatEuro(monthly)} per maand (indicatie) · ${config.above_max?.contact_label ?? "Neem contact op"} / ${config.above_max?.request_label ?? "op aanvraag"}`;
    }

    const hours = selectedHours();
    const compleet = isAbove
      ? null
      : tier.compleet?.[hours];
    const weekly = isAbove
      ? Math.round((monthly * 12) / 52)
      : tier.weekly;
    const annual = isAbove ? monthly * 10 : tier.annual;
    const valueText = isAbove
      ? `${questionsLabel}, ${contactText}`
      : `${tier.questions} vragen, ${formatEuro(monthly)} per maand`;

    range.setAttribute("aria-valuenow", String(isAbove ? 1000 : tier.questions));
    range.setAttribute("aria-valuetext", valueText);

    if (rangeOutput instanceof HTMLElement) {
      rangeOutput.textContent = questionsLabel;
    }

    if (monthlyEl instanceof HTMLElement) {
      monthlyEl.textContent = isAbove ? (config.above_max?.request_label ?? "op aanvraag") : formatEuro(monthly);
    }

    if (weeklyEl instanceof HTMLElement) {
      weeklyEl.textContent = isAbove ? "—" : `${formatEuro(weekly)} per week`;
    }

    if (annualEl instanceof HTMLElement) {
      annualEl.textContent = isAbove ? "—" : `${formatEuro(annual)} per jaar (10 maanden)`;
    }

    if (actionsEl instanceof HTMLElement) {
      actionsEl.textContent = isAbove ? "—" : String(tier.actions);
    }

    if (contactEl instanceof HTMLElement) {
      if (contactText) {
        contactEl.hidden = false;
        contactEl.textContent = contactText;
      } else {
        contactEl.hidden = true;
        contactEl.textContent = "";
      }
    }

    if (compleetEl instanceof HTMLElement) {
      compleetEl.textContent = typeof compleet === "number"
        ? formatEuro(compleet)
        : (config.above_max?.contact_label ?? "Neem contact op");
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
      // Default to 100 vragen (index of questions === 100).
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

  fetch("/assets/data/pricing.json", { credentials: "same-origin" })
    .then((response) => {
      if (!response.ok) {
        throw new Error("pricing.json missing");
      }

      return response.json();
    })
    .then(wire)
    .catch(() => {
      // Keep the static table visible when the config cannot be loaded.
      if (interactive instanceof HTMLElement) {
        interactive.hidden = true;
      }

      if (fallback instanceof HTMLElement) {
        fallback.hidden = false;
      }
    });
})();
