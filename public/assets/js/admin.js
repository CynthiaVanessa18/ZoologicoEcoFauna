document.addEventListener("DOMContentLoaded", () => {
  const cuerpoTabla = document.querySelector(".tabla-bitacora tbody");
  const paginacion = document.getElementById("paginacion-bitacora");
  const formularioFiltros = document.querySelector(".filtros-grid");

  if (!cuerpoTabla || !paginacion || !formularioFiltros) {
    return;
  }

  async function cargarPagina(pagina) {
    const parametros = new URLSearchParams(new FormData(formularioFiltros));
    parametros.set("pagina", pagina);
    parametros.set("ajax", "1");

    try {
      const respuesta = await fetch(`admin.php?${parametros.toString()}`);

      if (!respuesta.ok) {
        throw new Error("No fue posible cargar la bitácora.");
      }

      const datos = await respuesta.json();
      cuerpoTabla.innerHTML = datos.tbody;
      paginacion.innerHTML = datos.paginacion;
      document
        .querySelector(".tarjeta-admin")
        ?.scrollIntoView({ behavior: "smooth" });
    } catch (error) {
      console.error("Error cargando la página de bitácora:", error);
    }
  }

  paginacion.addEventListener("click", (evento) => {
    const enlace = evento.target.closest(".page-link");

    if (!enlace) {
      return;
    }

    evento.preventDefault();
    const pagina = enlace.dataset.pagina;

    if (pagina) {
      cargarPagina(pagina);
    }
  });

  formularioFiltros.addEventListener("submit", (evento) => {
    evento.preventDefault();
    cargarPagina("1");
  });
});
