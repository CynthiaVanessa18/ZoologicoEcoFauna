document.addEventListener("DOMContentLoaded", () => {
  const filtroCategoria = document.getElementById("filtroCategoriaProductos");

  filtroCategoria?.addEventListener("change", () => {
    filtroCategoria.form?.submit();
  });
});
