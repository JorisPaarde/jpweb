document.querySelectorAll("[data-opname-calculator]").forEach((calculator) => {
  const requestInput = calculator.querySelector('input[type="range"]');
  const rateInput = calculator.querySelector("[data-hourly-rate-input]");
  const monthlyValue = calculator.querySelector("[data-monthly-value]");
  const yearlyValue = calculator.querySelector("[data-yearly-value]");

  if (!(requestInput instanceof HTMLInputElement) || !(rateInput instanceof HTMLInputElement)) {
    return;
  }

  const handledShare = Number(calculator.dataset.avoidableShare) || 0.9;
  const minutesPerRequest = Number(calculator.dataset.travelMinutes) || 100;
  const currency = new Intl.NumberFormat("nl-NL", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  });

  const updateValue = () => {
    const requests = Number(requestInput.value);
    const hourlyRate = Math.max(0, Number(rateInput.value) || 0);
    const hoursPerMonth = (requests * handledShare * minutesPerRequest) / 60;

    if (monthlyValue) monthlyValue.textContent = currency.format(hoursPerMonth * hourlyRate);
    if (yearlyValue) yearlyValue.textContent = currency.format(hoursPerMonth * 12 * hourlyRate);
  };

  requestInput.addEventListener("input", updateValue);
  rateInput.addEventListener("input", updateValue);
  updateValue();
});
