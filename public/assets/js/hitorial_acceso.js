document.addEventListener("DOMContentLoaded", () => {
  const modalElemento = document.getElementById("modalMensajeAcceso");

  if (modalElemento) {
    bootstrap.Modal.getOrCreateInstance(modalElemento).show();
  }
});
