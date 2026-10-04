document.addEventListener("DOMContentLoaded", () => {
  if (typeof Swal === "undefined") {
    return;
  }

  const toast = Swal.mixin({
    toast: true,
    position: "bottom-end",
    showConfirmButton: false,
    timer: 2500,
    timerProgressBar: true,
    didOpen: (elemento) => {
      elemento.addEventListener("mouseenter", Swal.stopTimer);
      elemento.addEventListener("mouseleave", Swal.resumeTimer);
    },
  });

  document.addEventListener("submit", async (evento) => {
    const formulario = evento.target;

    if (!formulario.classList.contains("form-agregar-carrito")) {
      return;
    }

    evento.preventDefault();

    const datos = new FormData(formulario);
    datos.append("ajax", "1");

    const boton = formulario.querySelector(".btn-submit-carrito");
    const contenidoOriginal = boton?.innerHTML ?? "";

    if (boton) {
      boton.disabled = true;
      boton.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Agregando...';
    }

    try {
      const respuesta = await fetch("agregar_carrito.php", {
        method: "POST",
        body: datos,
      });

      const resultado = await respuesta.json().catch(() => null);

      if (!respuesta.ok || resultado?.status !== "success") {
        throw new Error(
          resultado?.message || "No fue posible agregar el producto.",
        );
      }

      const badge = document.getElementById("badge-carrito-total");

      if (badge) {
        badge.textContent = resultado.totalItems;
        badge.classList.toggle("d-none", resultado.totalItems <= 0);
      }

      toast.fire({
        icon: "success",
        title: "🛒 ¡Producto agregado al carrito!",
      });
    } catch (error) {
      console.error("Error al agregar al carrito:", error);
      toast.fire({
        icon: "error",
        title: error.message,
      });
    } finally {
      if (boton) {
        boton.disabled = false;
        boton.innerHTML = contenidoOriginal;
      }
    }
  });
});