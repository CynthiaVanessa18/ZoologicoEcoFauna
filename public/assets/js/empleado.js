document.addEventListener("DOMContentLoaded", () => {
  const contenedor = document.getElementById("lineasBoletos");
  const botonAgregar = document.getElementById("agregarLinea");
  const totalBoletos = document.getElementById("totalBoletos");

  if (!contenedor || !botonAgregar || !totalBoletos) {
    return;
  }

  function actualizar() {
    const lineas = contenedor.querySelectorAll(".linea-boleto");
    let totalBoletosCalculado = 0;
    let subtotal = 0;

    lineas.forEach((linea) => {
      const campoCantidad = linea.querySelector(".cantidad-boleto");
      const campoTipo = linea.querySelector(".tipo-boleto");
      const botonEliminar = linea.querySelector(".btn-quitar-linea");
      const cantidad = Number.parseInt(campoCantidad?.value ?? "0", 10) || 0;

      const precio = campoTipo?.value
        ? Number.parseFloat(
            campoTipo.options[campoTipo.selectedIndex]?.dataset.precio ?? "0",
          ) || 0
        : 0;

      totalBoletosCalculado += cantidad;
      subtotal += precio * cantidad;

      if (botonEliminar) {
        botonEliminar.disabled = lineas.length === 1;
      }
    });

    const iva = subtotal * 0.13;
    const total = subtotal + iva;
    const formatoMoneda = new Intl.NumberFormat("es-CR", {
      style: "currency",
      currency: "CRC",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

    totalBoletos.textContent = totalBoletosCalculado;

    const subtotalVista = document.getElementById("subtotalVista");
    const ivaVista = document.getElementById("ivaVista");
    const totalVista = document.getElementById("totalVista");

    if (subtotalVista)
      subtotalVista.textContent = formatoMoneda.format(subtotal);
    if (ivaVista) ivaVista.textContent = formatoMoneda.format(iva);
    if (totalVista) totalVista.textContent = formatoMoneda.format(total);
  }

  botonAgregar.addEventListener("click", () => {
    const primera = contenedor.querySelector(".linea-boleto");

    if (!primera) {
      return;
    }

    const nueva = primera.cloneNode(true);
    nueva.querySelector(".tipo-boleto").value = "";
    nueva.querySelector(".cantidad-boleto").value = "1";
    contenedor.appendChild(nueva);
    actualizar();
  });

  contenedor.addEventListener("click", (evento) => {
    const boton = evento.target.closest(".btn-quitar-linea");

    if (!boton) {
      return;
    }

    boton.closest(".linea-boleto")?.remove();
    actualizar();
  });

  contenedor.addEventListener("input", actualizar);
  contenedor.addEventListener("change", actualizar);
  actualizar();
});

document.addEventListener("DOMContentLoaded", () => {
  const configuracion = document.getElementById("configEmpleado");
  const modalElemento = document.getElementById("modalCancelarTicket");
  const formularioCancelar = document.getElementById("formCancelarTicket");
  const campoTicket = document.getElementById("idTicketCancelar");
  const mensaje = document.getElementById("mensajeCancelarTicket");
  const modal = modalElemento
    ? bootstrap.Modal.getOrCreateInstance(modalElemento)
    : null;

  document.addEventListener("click", (evento) => {
    const botonCancelar = evento.target.closest(".btn-cancelar-ticket");

    if (
      !botonCancelar ||
      !modal ||
      !formularioCancelar ||
      !campoTicket ||
      !mensaje
    ) {
      return;
    }

    const idTicket = Number.parseInt(botonCancelar.dataset.ticket ?? "", 10);

    if (!Number.isInteger(idTicket) || idTicket <= 0) {
      return;
    }

    campoTicket.value = String(idTicket);
    mensaje.textContent = `¿Deseas cancelar el ticket #${idTicket}?`;
    modal.show();
  });

  const paginacion = document.getElementById("paginacion-tickets");

  paginacion?.addEventListener("click", async (evento) => {
    const enlace = evento.target.closest(".page-link");

    if (!enlace) {
      return;
    }

    evento.preventDefault();
    const pagina = enlace.dataset.pagina;

    if (!pagina) {
      return;
    }

    const parametros = new URLSearchParams({
      pagina,
      ticket: configuracion?.dataset.filtroTicket ?? "",
      estado: configuracion?.dataset.filtroEstado ?? "",
      pago: configuracion?.dataset.filtroPago ?? "",
      fecha: configuracion?.dataset.filtroFecha ?? "",
      ajax: "1",
    });

    try {
      const respuesta = await fetch(`empleado.php?${parametros.toString()}`);

      if (!respuesta.ok) {
        throw new Error("No fue posible cargar los tickets.");
      }

      const datos = await respuesta.json();
      const cuerpoTabla = document.getElementById("tbody-tickets");

      if (cuerpoTabla) cuerpoTabla.innerHTML = datos.tbody;
      paginacion.innerHTML = datos.paginacion;
    } catch (error) {
      console.error("Error al paginar:", error);
    }
  });

  function guardarScroll() {
    if (window.scrollY > 0) {
      sessionStorage.setItem("emp_scroll_y", String(window.scrollY));
    }
  }

  const posicionGuardada = sessionStorage.getItem("emp_scroll_y");

  if (posicionGuardada !== null) {
    const posicion = Number.parseInt(posicionGuardada, 10);
    window.scrollTo({ top: posicion, behavior: "auto" });
    setTimeout(() => window.scrollTo({ top: posicion, behavior: "auto" }), 50);
    setTimeout(() => {
      window.scrollTo({ top: posicion, behavior: "auto" });
      sessionStorage.removeItem("emp_scroll_y");
    }, 200);
  }

  document.addEventListener("click", (evento) => {
    const control = evento.target.closest(
      'button[type="submit"], input[type="submit"], .btn-filtrar, .btn-limpiar',
    );

    if (control) {
      guardarScroll();
    }
  });

  document.addEventListener("submit", guardarScroll);
});