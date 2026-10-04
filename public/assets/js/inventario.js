document.addEventListener("DOMContentLoaded", () => {
  const modalEditarElemento = document.getElementById("modalEditarInventario");
  const modalEditar = modalEditarElemento
    ? bootstrap.Modal.getOrCreateInstance(modalEditarElemento)
    : null;

  document.addEventListener("click", async (evento) => {
    const botonEditar = evento.target.closest(".btn-editar-inventario");

    if (botonEditar && modalEditar) {
      document.getElementById("editIdInventario").value =
        botonEditar.dataset.id ?? "";
      document.getElementById("editNombreProducto").value =
        botonEditar.dataset.nombre ?? "";
      document.getElementById("editUnidadMedida").value =
        botonEditar.dataset.unidad ?? "";
      document.getElementById("editCantidad").value =
        botonEditar.dataset.cantidad ?? "";
      document.getElementById("editFechaIngreso").value =
        botonEditar.dataset.ingreso ?? "";
      document.getElementById("editFechaCaducidad").value =
        botonEditar.dataset.caducidad ?? "";
      modalEditar.show();
      return;
    }

    const enlacePagina = evento.target.closest(
      "#paginacion-inventario .page-link",
    );

    if (!enlacePagina) {
      return;
    }

    evento.preventDefault();
    const pagina = enlacePagina.dataset.pagina;

    if (!pagina) {
      return;
    }

    try {
      const respuesta = await fetch(`inventario.php?pagina=${pagina}&ajax=1`);

      if (!respuesta.ok) {
        throw new Error("No fue posible cargar el inventario.");
      }

      const datos = await respuesta.json();
      const cuerpoTabla = document.getElementById("tbody-inventario");
      const paginacion = document.getElementById("paginacion-inventario");

      if (cuerpoTabla) cuerpoTabla.innerHTML = datos.tbody;
      if (paginacion) paginacion.innerHTML = datos.paginacion;

      document
        .getElementById("seccion-inventario")
        ?.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch (error) {
      console.error("Error al paginar:", error);
    }
  });

  document.addEventListener("submit", (evento) => {
    const formulario = evento.target;

    if (!formulario.classList.contains("form-eliminar-lote")) {
      guardarScroll();
      return;
    }

    evento.preventDefault();

    Swal.fire({
      title: "¿Deseas ocultar este lote?",
      text: "El lote seguirá en la base de datos, pero dejará de mostrarse como activo.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#d33",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Sí, ocultar",
      cancelButtonText: "Cancelar",
      focusCancel: true,
    }).then((resultado) => {
      if (resultado.isConfirmed) {
        guardarScroll();
        HTMLFormElement.prototype.submit.call(formulario);
      }
    });
  });

  function guardarScroll() {
    if (window.scrollY > 0) {
      sessionStorage.setItem("inv_scroll_y", String(window.scrollY));
    }
  }

  const posicionGuardada = sessionStorage.getItem("inv_scroll_y");

  if (posicionGuardada !== null) {
    const posicion = Number.parseInt(posicionGuardada, 10);
    window.scrollTo({ top: posicion, behavior: "auto" });
    setTimeout(() => window.scrollTo({ top: posicion, behavior: "auto" }), 50);
    setTimeout(() => {
      window.scrollTo({ top: posicion, behavior: "auto" });
      sessionStorage.removeItem("inv_scroll_y");
    }, 200);
  }

  document.addEventListener("click", (evento) => {
    const control = evento.target.closest(
      'button[type="submit"], input[type="submit"], .btn-editar-lote, .btn-eliminar-lote',
    );

    if (control) {
      guardarScroll();
    }
  });
});