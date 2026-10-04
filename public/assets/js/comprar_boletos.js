document.addEventListener("DOMContentLoaded", () => {
  const inputsCantidad = document.querySelectorAll(".input-cantidad");
  const subtotalElemento = document.getElementById("resumen_subtotal");
  const ivaElemento = document.getElementById("resumen_iva");
  const totalElemento = document.getElementById("resumen_total");

  if (!subtotalElemento || !ivaElemento || !totalElemento) {
    return;
  }

  const formatearColones = (monto) =>
    `₡${monto.toLocaleString("es-CR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;

  function calcularTotales() {
    let subtotal = 0;

    inputsCantidad.forEach((input) => {
      const cantidad = Number.parseInt(input.value, 10) || 0;
      const precio = Number.parseFloat(input.dataset.precio ?? "0") || 0;
      subtotal += cantidad * precio;
    });

    const iva = subtotal * 0.13;
    const total = subtotal + iva;

    subtotalElemento.textContent = formatearColones(subtotal);
    ivaElemento.textContent = formatearColones(iva);
    totalElemento.textContent = formatearColones(total);
  }

  inputsCantidad.forEach((input) => {
    input.addEventListener("input", calcularTotales);
    input.addEventListener("change", calcularTotales);
  });

  calcularTotales();
});
