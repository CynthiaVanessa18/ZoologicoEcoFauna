document.addEventListener("DOMContentLoaded", () => {
  const configuracion = document.getElementById("configVeterinario");
  let historiales = [];

  try {
    historiales = JSON.parse(configuracion?.dataset.historiales ?? "[]");
  } catch (error) {
    console.error("No fue posible leer el historial veterinario:", error);
  }

  const escaparHtml = (valor) => {
    const elemento = document.createElement("div");
    elemento.textContent = valor == null ? "" : String(valor);
    return elemento.innerHTML;
  };

  const selectProducto = document.getElementById("id_producto");
  const inputCantidad = document.getElementById("cantidad_recomendada");
  const unidadBadge = document.getElementById("unidad_badge");
  const selectAnimal = document.getElementById("id_animal");
  const botonRegistrarDieta = document.querySelector(
    'button[name="registrar_dieta"]',
  );
  const fichaAnimal = document.getElementById("ficha_animal_card");
  const fichaCodigo = document.getElementById("fa_codigo");
  const fichaAltura = document.getElementById("fa_altura");
  const fichaPeso = document.getElementById("fa_peso");
  const fichaEstado = document.getElementById("fa_estado");
  const fichaAlergias = document.getElementById("fa_alergias");
  const fichaHistorial = document.getElementById("fa_historial_clinico");

  function actualizarUnidad() {
    if (!selectProducto || !inputCantidad) {
      return;
    }

    const opcion = selectProducto.options[selectProducto.selectedIndex];
    const unidad = opcion?.dataset.unidad ?? "";

    inputCantidad.placeholder = "Ejemplo: 5.50";
    if (unidadBadge) unidadBadge.textContent = unidad || "-";
  }

  function restaurarBotonDieta() {
    if (!botonRegistrarDieta) {
      return;
    }

    botonRegistrarDieta.disabled = false;
    botonRegistrarDieta.style.opacity = "1";
    botonRegistrarDieta.style.cursor = "pointer";
    botonRegistrarDieta.innerHTML =
      '<i class="bi bi-check2-circle me-1"></i> Registrar dieta y horario';
  }

  function mostrarAlergiasOriginales(opcionAnimal) {
    if (!fichaAlergias) {
      return;
    }

    const alergias = opcionAnimal?.dataset.alergias || "Ninguna";
    fichaAlergias.className = "alert py-1 px-2 m-0";
    fichaAlergias.style.borderColor = "";

    if (alergias.toLowerCase() === "ninguna") {
      fichaAlergias.textContent = alergias;
      fichaAlergias.classList.add("alert-success");
      fichaAlergias.style.borderColor = "#c8d9bd";
      return;
    }

    fichaAlergias.classList.add("alert-danger");
    fichaAlergias.innerHTML = `⚠️ <strong>Alergias detectadas:</strong> ${escaparHtml(alergias)}`;
  }

  function validarAlergias() {
    if (!selectAnimal || !selectProducto || !botonRegistrarDieta) {
      return;
    }

    const opcionAnimal = selectAnimal.options[selectAnimal.selectedIndex];
    const opcionProducto = selectProducto.options[selectProducto.selectedIndex];

    if (!opcionAnimal?.value || !opcionProducto?.value) {
      restaurarBotonDieta();
      return;
    }

    const alergias = (opcionAnimal.dataset.alergias || "").toLowerCase();
    const nombreProducto = opcionProducto.textContent.trim().toLowerCase();

    if (alergias !== "ninguna" && alergias.includes(nombreProducto)) {
      if (fichaAlergias) {
        fichaAlergias.className = "alert alert-danger py-1 px-2 m-0 fw-bold";
        fichaAlergias.innerHTML = `❌ ¡ALERGIA DETECTADA! El animal es sensible o alérgico a "${escaparHtml(opcionProducto.textContent.trim())}".`;
      }

      botonRegistrarDieta.disabled = true;
      botonRegistrarDieta.style.opacity = "0.6";
      botonRegistrarDieta.style.cursor = "not-allowed";
      botonRegistrarDieta.innerHTML =
        '<i class="bi bi-exclamation-triangle-fill me-1"></i> Bloqueado por Alergia';
      return;
    }

    restaurarBotonDieta();
    mostrarAlergiasOriginales(opcionAnimal);
  }

  function mostrarHistorialAnimal(idAnimal) {
    if (!fichaHistorial) {
      return;
    }

    const registros = historiales.filter(
      (registro) => Number.parseInt(registro.id_animal, 10) === idAnimal,
    );

    if (registros.length === 0) {
      fichaHistorial.innerHTML = `
        <div class="alert alert-success py-2 px-3 m-0" style="font-size: 0.78rem; border-color: #c8d9bd;">
          <i class="bi bi-shield-check me-1"></i>
          Sin antecedentes clínicos registrados.
        </div>`;
      return;
    }

    const elementos = registros
      .map((registro) => {
        const estado = registro.fecha_recuperacion
          ? `<span class="badge bg-success ms-1">Recuperado (${escaparHtml(registro.fecha_recuperacion)})</span>`
          : '<span class="badge bg-danger ms-1">En tratamiento</span>';

        return `
          <div class="p-2 border rounded bg-white text-muted" style="font-size: 0.76rem; border-color: var(--borde) !important;">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <strong class="text-dark"><i class="bi bi-virus me-1 text-danger"></i>${escaparHtml(registro.enfermedad)}</strong>
              ${estado}
            </div>
            <div class="mb-1 text-muted"><strong>Diag:</strong> ${escaparHtml(registro.fecha_diagnostico)}</div>
            <div class="mb-1 text-muted"><strong>Tratamiento:</strong> ${escaparHtml(registro.tratamiento || "Ninguno")}</div>
            <div class="text-muted"><strong>Obs:</strong> ${escaparHtml(registro.observaciones || "Ninguna")}</div>
          </div>`;
      })
      .join("");

    fichaHistorial.innerHTML = `<div class="list-group gap-2 mt-1">${elementos}</div>`;
  }

  function actualizarFichaAnimal() {
    if (!selectAnimal) {
      return;
    }

    const opcion = selectAnimal.options[selectAnimal.selectedIndex];

    if (!opcion?.value) {
      if (fichaAnimal) fichaAnimal.style.display = "none";
      validarAlergias();
      return;
    }

    const estado = opcion.dataset.estado || "Activo";

    if (fichaCodigo) fichaCodigo.textContent = opcion.dataset.codigo || "N/A";
    if (fichaAltura) fichaAltura.textContent = opcion.dataset.altura || "0.00";
    if (fichaPeso) fichaPeso.textContent = opcion.dataset.peso || "0.00";

    if (fichaEstado) {
      fichaEstado.textContent = estado;
      fichaEstado.className = "badge";

      if (estado === "Activo") {
        fichaEstado.classList.add("bg-success");
      } else if (estado === "Cuarentena") {
        fichaEstado.classList.add("bg-warning", "text-dark");
      } else {
        fichaEstado.classList.add("bg-danger");
      }
    }

    mostrarAlergiasOriginales(opcion);
    mostrarHistorialAnimal(Number.parseInt(opcion.value, 10));
    if (fichaAnimal) fichaAnimal.style.display = "block";
    validarAlergias();
  }

  selectProducto?.addEventListener("change", () => {
    actualizarUnidad();
    validarAlergias();
  });
  selectAnimal?.addEventListener("change", actualizarFichaAnimal);
  actualizarUnidad();
  actualizarFichaAnimal();

  const modalResultado = document.getElementById("modalResultado");
  if (modalResultado) {
    bootstrap.Modal.getOrCreateInstance(modalResultado).show();
  }

  const selectCondicion = document.getElementById("id_enfermedad");
  const ayudaCondicion = document.getElementById("ayudaTipoCondicion");

  selectCondicion?.addEventListener("change", () => {
    if (!ayudaCondicion) return;

    const tipo =
      selectCondicion.options[selectCondicion.selectedIndex]?.dataset.tipo ??
      "";

    if (tipo === "Alergia") {
      ayudaCondicion.innerHTML =
        '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
        "Si permanece vigente, esta alergia aparecerá en la ficha y protegerá el registro de dietas.";
      ayudaCondicion.classList.add("ayuda-alergia");
    } else {
      ayudaCondicion.textContent =
        "Registra el tratamiento y deja la recuperación vacía mientras la condición siga activa.";
      ayudaCondicion.classList.remove("ayuda-alergia");
    }
  });

  const fechaDiagnostico = document.getElementById("fecha_diagnostico");
  const fechaRecuperacion = document.getElementById("fecha_recuperacion");

  function actualizarMinimoRecuperacion() {
    if (fechaRecuperacion) {
      fechaRecuperacion.min = fechaDiagnostico?.value || "";
    }
  }

  fechaDiagnostico?.addEventListener("change", actualizarMinimoRecuperacion);
  actualizarMinimoRecuperacion();

  const fechaVacunacion = document.getElementById("fecha_vacunacion");
  const fechaProximaVacuna = document.getElementById("fecha_proxima_vacuna");

  function calcularDiaSiguiente(valorFecha) {
    if (!valorFecha) return "";
    const fecha = new Date(`${valorFecha}T00:00:00`);
    fecha.setDate(fecha.getDate() + 1);
    return fecha.toISOString().slice(0, 10);
  }

  function actualizarMinimoProximaVacuna() {
    if (fechaProximaVacuna) {
      fechaProximaVacuna.min = calcularDiaSiguiente(fechaVacunacion?.value);
    }
  }

  fechaVacunacion?.addEventListener("change", actualizarMinimoProximaVacuna);
  actualizarMinimoProximaVacuna();

  const filtroHistorial = document.getElementById("filtroHistorialMedico");

  function aplicarFiltroHistorial() {
    if (!filtroHistorial) return;

    let visibles = 0;
    document.querySelectorAll(".fila-historial-medico").forEach((fila) => {
      const coincide =
        filtroHistorial.value === "" ||
        fila.dataset.animal === filtroHistorial.value;
      fila.classList.toggle("d-none", !coincide);
      if (coincide) visibles += 1;
    });

    document
      .getElementById("filaSinCoincidenciasMedicas")
      ?.classList.toggle("d-none", visibles !== 0);
  }

  filtroHistorial?.addEventListener("change", aplicarFiltroHistorial);

  const fechaVacunaEditada = document.getElementById("editarFechaVacuna");
  const proximaVacunaEditada = document.getElementById("editarProximaVacuna");

  function actualizarMinimoVacunaEditada() {
    if (proximaVacunaEditada) {
      proximaVacunaEditada.min = calcularDiaSiguiente(
        fechaVacunaEditada?.value,
      );
    }
  }

  fechaVacunaEditada?.addEventListener("change", actualizarMinimoVacunaEditada);

  document.addEventListener("click", (evento) => {
    const botonHistorial = evento.target.closest(".btn-editar-historial");
    if (botonHistorial) {
      document.getElementById("editarIdHistorial").value =
        botonHistorial.dataset.id || "";
      document.getElementById("editarResumenHistorial").textContent =
        `${botonHistorial.dataset.animal || "Animal"} · ${botonHistorial.dataset.condicion || "Condición"}`;
      document.getElementById("editarFechaDiagnostico").value =
        botonHistorial.dataset.diagnostico || "";
      const recuperacion = document.getElementById("editarFechaRecuperacion");
      recuperacion.value = botonHistorial.dataset.recuperacion || "";
      recuperacion.min = botonHistorial.dataset.diagnostico || "";
      document.getElementById("editarTratamiento").value =
        botonHistorial.dataset.tratamiento || "";
      document.getElementById("editarObservaciones").value =
        botonHistorial.dataset.observaciones || "";
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("modalEditarHistorial"),
      ).show();
      return;
    }

    const botonVacuna = evento.target.closest(".btn-editar-vacuna");
    if (botonVacuna) {
      document.getElementById("editarIdVacunacion").value =
        botonVacuna.dataset.id || "";
      document.getElementById("editarAnimalVacuna").textContent =
        botonVacuna.dataset.animal || "Animal";
      document.getElementById("editarNombreVacuna").value =
        botonVacuna.dataset.nombre || "";
      fechaVacunaEditada.value = botonVacuna.dataset.fecha || "";
      proximaVacunaEditada.value = botonVacuna.dataset.proxima || "";
      document.getElementById("editarObservacionesVacuna").value =
        botonVacuna.dataset.observaciones || "";
      actualizarMinimoVacunaEditada();
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("modalEditarVacuna"),
      ).show();
      return;
    }

    const botonDieta = evento.target.closest(".btn-editar-dieta");
    if (botonDieta) {
      document.getElementById("editarIdAlimentacion").value =
        botonDieta.dataset.id || "";
      document.getElementById("editarAnimalDieta").value =
        botonDieta.dataset.idAnimal || "";
      document.getElementById("editarProductoDieta").value =
        botonDieta.dataset.idProducto || "";
      document.getElementById("editarCantidadDiet").value =
        botonDieta.dataset.cantidad || "";
      document.getElementById("editarHoraDiet").value =
        botonDieta.dataset.hora || "";
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("modalEditarDieta"),
      ).show();
      return;
    }

    const botonEliminarDieta = evento.target.closest(".btn-eliminar-dieta");
    if (botonEliminarDieta) {
      document.getElementById("idDietaEliminar").value =
        botonEliminarDieta.dataset.id || "";
      document.getElementById("mensajeEliminarDieta").textContent =
        `¿Deseas eliminar el horario para ${botonEliminarDieta.dataset.animal || "el animal"}?`;
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("modalEliminarDieta"),
      ).show();
    }
  });

  const paginas = {
    historial: configuracion?.dataset.pagHist || "1",
    vacunas: configuracion?.dataset.pagVac || "1",
    alertas: configuracion?.dataset.pagAlert || "1",
    dietas: configuracion?.dataset.pagDiet || "1",
  };

  const tablas = [
    {
      id: "paginacion-historial",
      cuerpo: "cuerpoHistorialMedico",
      nombre: "historial",
      parametro: "pag_hist",
    },
    {
      id: "paginacion-vacunacion",
      cuerpo: "tbody-vacunas",
      nombre: "vacunas",
      parametro: "pag_vac",
    },
    {
      id: "paginacion-alertas",
      cuerpo: "tbody-alertas",
      nombre: "alertas",
      parametro: "pag_alert",
    },
    {
      id: "paginacion-dietas",
      cuerpo: "tbody-dietas",
      nombre: "dietas",
      parametro: "pag_diet",
    },
  ];

  tablas.forEach((tabla) => {
    const paginacion = document.getElementById(tabla.id);

    paginacion?.addEventListener("click", async (evento) => {
      const enlace = evento.target.closest(".page-link");

      if (!enlace) return;
      evento.preventDefault();

      const pagina = enlace.dataset.pagina;
      if (!pagina) return;

      paginas[tabla.nombre] = pagina;
      const parametros = new URLSearchParams({
        tabla: tabla.nombre,
        ajax: "1",
        pag_hist: paginas.historial,
        pag_vac: paginas.vacunas,
        pag_alert: paginas.alertas,
        pag_diet: paginas.dietas,
      });

      if (tabla.nombre === "dietas" && configuracion?.dataset.animalFiltro) {
        parametros.set("animal", configuracion.dataset.animalFiltro);
      }

      try {
        const respuesta = await fetch(
          `veterinario.php?${parametros.toString()}`,
        );
        if (!respuesta.ok) {
          throw new Error("No fue posible cargar la tabla veterinaria.");
        }

        const datos = await respuesta.json();
        const cuerpo = document.getElementById(tabla.cuerpo);
        if (cuerpo) cuerpo.innerHTML = datos.tbody;
        paginacion.innerHTML = datos.paginacion;

        if (tabla.nombre === "historial") {
          aplicarFiltroHistorial();
        }
      } catch (error) {
        console.error("Error al paginar:", error);
      }
    });
  });

  document.addEventListener("submit", (evento) => {
    const formulario = evento.target;
    const esHistorial = formulario.classList.contains(
      "form-eliminar-historial",
    );
    const esVacuna = formulario.classList.contains("form-eliminar-vacuna");

    if (!esHistorial && !esVacuna) {
      guardarScroll();
      return;
    }

    evento.preventDefault();

    Swal.fire({
      title: esHistorial
        ? "¿Estás seguro de eliminar este historial médico?"
        : "¿Estás seguro de eliminar esta vacunación?",
      text: "Esta acción realizará un borrado lógico en el sistema.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#d33",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Sí, eliminar",
      cancelButtonText: "Cancelar",
      focusCancel: true,
    }).then((resultado) => {
      if (!resultado.isConfirmed) return;

      const nombreCampo = esHistorial
        ? "eliminar_historial"
        : "eliminar_vacuna";

      if (!formulario.querySelector(`input[name="${nombreCampo}"]`)) {
        const campo = document.createElement("input");
        campo.type = "hidden";
        campo.name = nombreCampo;
        campo.value = "1";
        formulario.appendChild(campo);
      }

      guardarScroll();
      HTMLFormElement.prototype.submit.call(formulario);
    });
  });

  document.getElementById("animal")?.addEventListener("change", (evento) => {
    evento.target.form?.submit();
  });

  function guardarScroll() {
    if (window.scrollY > 0) {
      sessionStorage.setItem("vet_scroll_y", String(window.scrollY));
    }
  }

  const posicionGuardada = sessionStorage.getItem("vet_scroll_y");

  if (posicionGuardada !== null) {
    const posicion = Number.parseInt(posicionGuardada, 10);
    window.scrollTo({ top: posicion, behavior: "auto" });
    setTimeout(() => window.scrollTo({ top: posicion, behavior: "auto" }), 50);
    setTimeout(() => {
      window.scrollTo({ top: posicion, behavior: "auto" });
      sessionStorage.removeItem("vet_scroll_y");
    }, 200);
  }

  document.addEventListener("click", (evento) => {
    const control = evento.target.closest(
      'button[type="submit"], input[type="submit"], .btn-editar-dieta, .btn-eliminar-dieta, .btn-editar-historial, .btn-editar-vacuna',
    );

    if (control) guardarScroll();
  });
});
