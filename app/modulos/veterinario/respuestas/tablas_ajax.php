<?php

/** @var mysqli $conexion */

/** @var array $registrosMedicos */
/** @var int $pagActualHist */
/** @var int $totalPagHist */

/** @var array $registrosVacunas */
/** @var int $pagActualVac */
/** @var int $totalPagVac */

/** @var bool $errorCargaHorarios */
/** @var array $listaHorarios */
/** @var int $pagActualDiet */
/** @var int $totalPagDiet */

/** @var array $historialAlertas */
/** @var int $pagActualAlert */
/** @var int $totalPagAlert */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(403);
    exit("Acceso no permitido.");
}

if (isset($_GET["ajax"])) {
    $tabla = trim($_GET["tabla"] ?? "");
    $tbodyHtml = "";
    $pagHtml = "";

    /* =================================================
       HISTORIAL MÉDICO
    ================================================= */

    if ($tabla === "historial") {
        ob_start();

        if (count($registrosMedicos) === 0) {
            echo '
                <tr class="fila-sin-registros">
                    <td colspan="6">
                        <div class="estado-vacio">
                            <i class="bi bi-journal-plus"></i>
                            <h3>No hay antecedentes médicos</h3>
                            <p>
                                Los diagnósticos, tratamientos y alergias
                                aparecerán aquí.
                            </p>
                        </div>
                    </td>
                </tr>
            ';
        } else {
            foreach (
                $registrosMedicos as $registroMedico
            ) {
                $registroActivo =
                    $registroMedico[
                        "fecha_recuperacion"
                    ] === null;

                $esAlergia =
                    $registroMedico["tipo"] ===
                    "Alergia";

                echo '<tr
                    class="fila-historial-medico"
                    data-animal="' .
                    (int) $registroMedico[
                        "id_animal"
                    ] .
                    '">';

                echo '
                    <td>
                        <div class="animal-celda">
                            <span class="animal-icono">
                                <i class="fa-solid fa-paw"></i>
                            </span>

                            <div>
                                <strong>' .
                    htmlspecialchars(
                        $registroMedico[
                            "nombre_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</strong>

                                <small>' .
                    htmlspecialchars(
                        $registroMedico[
                            "codigo_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</small>
                            </div>
                        </div>
                    </td>
                ';

                echo "<td>";

                if ($esAlergia) {
                    echo '
                        <span class="
                            etiqueta-condicion
                            etiqueta-alergia
                        ">
                            <i class="
                                bi
                                bi-exclamation-triangle-fill
                            "></i>

                            Alergia: ' .
                        htmlspecialchars(
                            $registroMedico[
                                "condicion"
                            ],
                            ENT_QUOTES,
                            "UTF-8"
                        ) .
                        '
                        </span>
                    ';
                } else {
                    echo '
                        <span class="
                            etiqueta-condicion
                            etiqueta-enfermedad
                        ">
                            <i class="bi bi-virus"></i>

                            Enfermedad: ' .
                        htmlspecialchars(
                            $registroMedico[
                                "condicion"
                            ],
                            ENT_QUOTES,
                            "UTF-8"
                        ) .
                        '
                        </span>
                    ';
                }

                echo "</td>";

                echo "<td>" .
                    date(
                        "d/m/Y",
                        strtotime(
                            $registroMedico[
                                "fecha_diagnostico"
                            ]
                        )
                    ) .
                    "</td>";

                echo '
                    <td class="detalle-medico">
                        <strong>Tratamiento:</strong> ' .
                    nl2br(
                        htmlspecialchars(
                            $registroMedico[
                                "tratamiento"
                            ] ?: "Sin tratamiento indicado",
                            ENT_QUOTES,
                            "UTF-8"
                        )
                    ) .
                    '

                        <br>

                        <small class="text-muted">
                            <strong>Obs:</strong> ' .
                    nl2br(
                        htmlspecialchars(
                            $registroMedico[
                                "observaciones"
                            ] ?: "Sin observaciones",
                            ENT_QUOTES,
                            "UTF-8"
                        )
                    ) .
                    '
                        </small>
                    </td>
                ';

                echo "<td>";

                if ($registroActivo) {
                    echo '
                        <span class="
                            estado-medico
                            estado-activo
                        ">
                            Activo
                        </span>
                    ';
                } else {
                    echo '
                        <span class="
                            estado-medico
                            estado-recuperado
                        ">
                            Recuperado (' .
                        date(
                            "d/m/Y",
                            strtotime(
                                $registroMedico[
                                    "fecha_recuperacion"
                                ]
                            )
                        ) .
                        ')
                        </span>
                    ';
                }

                echo "</td>";

                echo "<td>";

                echo '
                    <button
                        type="button"
                        class="
                            btn
                            btn-outline-success
                            btn-sm
                            btn-editar-historial
                        "
                        data-id="' .
                    (int) $registroMedico[
                        "id_historial"
                    ] .
                    '"
                        data-animal="' .
                    htmlspecialchars(
                        $registroMedico[
                            "nombre_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-condicion="' .
                    htmlspecialchars(
                        $registroMedico[
                            "condicion"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-diagnostico="' .
                    htmlspecialchars(
                        $registroMedico[
                            "fecha_diagnostico"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-recuperacion="' .
                    htmlspecialchars(
                        $registroMedico[
                            "fecha_recuperacion"
                        ] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-tratamiento="' .
                    htmlspecialchars(
                        $registroMedico[
                            "tratamiento"
                        ] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-observaciones="' .
                    htmlspecialchars(
                        $registroMedico[
                            "observaciones"
                        ] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        title="
                            Actualizar tratamiento
                            y recuperación
                        "
                    >
                        <i class="
                            bi
                            bi-pencil-square
                        "></i>
                    </button>
                ';

                echo '
                    <form
                        method="POST"
                        class="
                            d-inline
                            form-eliminar-historial
                        "
                    >
                        <input
                            type="hidden"
                            name="eliminar_historial"
                            value="1"
                        >

                        <input
                            type="hidden"
                            name="id_historial"
                            value="' .
                    (int) $registroMedico[
                        "id_historial"
                    ] .
                    '"
                        >

                        <button
                            type="submit"
                            name="btn_eliminar_historial"
                            class="
                                btn
                                btn-outline-danger
                                btn-sm
                            "
                            title="
                                Eliminar historial médico
                            "
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                ';

                echo "</td>";
                echo "</tr>";
            }
        }

        $tbodyHtml = ob_get_clean();

        ob_start();

        if ($totalPagHist > 1) {
            echo '
                <ul class="
                    pagination
                    justify-content-center
                ">
            ';

            echo '<li class="page-item ' .
                (
                    $pagActualHist <= 1
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualHist - 1) .
                '"
                data-tabla="historial"
            >
                Anterior
            </a>';

            echo "</li>";

            for (
                $i = 1;
                $i <= $totalPagHist;
                $i++
            ) {
                echo '<li class="page-item ' .
                    (
                        $pagActualHist === $i
                            ? "active"
                            : ""
                    ) .
                    '">';

                echo '<a
                    class="page-link"
                    href="#"
                    data-pagina="' .
                    $i .
                    '"
                    data-tabla="historial"
                >' .
                    $i .
                    "</a>";

                echo "</li>";
            }

            echo '<li class="page-item ' .
                (
                    $pagActualHist >=
                    $totalPagHist
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualHist + 1) .
                '"
                data-tabla="historial"
            >
                Siguiente
            </a>';

            echo "</li>";
            echo "</ul>";
        }

        $pagHtml = ob_get_clean();
    }


    /* =================================================
       VACUNACIONES
    ================================================= */

    if ($tabla === "vacunas") {
        ob_start();

        if (count($registrosVacunas) === 0) {
            echo '
                <tr>
                    <td colspan="6">
                        <div class="estado-vacio">
                            <i class="bi bi-shield-slash"></i>
                            <h3>No hay vacunas registradas</h3>
                            <p>
                                El historial de vacunación
                                aparecerá aquí.
                            </p>
                        </div>
                    </td>
                </tr>
            ';
        } else {
            foreach (
                $registrosVacunas as $registroVacuna
            ) {
                $diasVacuna =
                    $registroVacuna[
                        "dias_restantes"
                    ];

                if ($diasVacuna === null) {
                    $claseVacuna =
                        "vacuna-unica";

                    $textoVacuna =
                        "Dosis única";
                } elseif ($diasVacuna < 0) {
                    $claseVacuna =
                        "vacuna-vencida";

                    $textoVacuna =
                        "Vencida hace " .
                        abs($diasVacuna) .
                        " día(s)";
                } elseif ($diasVacuna === 0) {
                    $claseVacuna =
                        "vacuna-hoy";

                    $textoVacuna =
                        "Hoy";
                } elseif ($diasVacuna <= 15) {
                    $claseVacuna =
                        "vacuna-proxima";

                    $textoVacuna =
                        "En " .
                        $diasVacuna .
                        " día(s)";
                } else {
                    $claseVacuna =
                        "vacuna-programada";

                    $textoVacuna =
                        "Programada";
                }

                echo "<tr>";

                echo '
                    <td>
                        <div class="animal-celda">
                            <span class="animal-icono">
                                <i class="fa-solid fa-paw"></i>
                            </span>

                            <div>
                                <strong>' .
                    htmlspecialchars(
                        $registroVacuna[
                            "nombre_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</strong>

                                <small>' .
                    htmlspecialchars(
                        $registroVacuna[
                            "codigo_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</small>
                            </div>
                        </div>
                    </td>
                ';

                echo "<td><strong>" .
                    htmlspecialchars(
                        $registroVacuna[
                            "nombre_vacuna"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    "</strong></td>";

                echo "<td>" .
                    date(
                        "d/m/Y",
                        strtotime(
                            $registroVacuna[
                                "fecha_vacunacion"
                            ]
                        )
                    ) .
                    "</td>";

                echo "<td>";

                if (
                    $registroVacuna[
                        "fecha_proxima_vacuna"
                    ] !== null
                ) {
                    echo "<strong>" .
                        date(
                            "d/m/Y",
                            strtotime(
                                $registroVacuna[
                                    "fecha_proxima_vacuna"
                                ]
                            )
                        ) .
                        "</strong>";
                }

                echo ' <span class="estado-vacuna ' .
                    $claseVacuna .
                    '">' .
                    $textoVacuna .
                    "</span>";

                echo "</td>";

                echo '
                    <td class="detalle-medico">' .
                    nl2br(
                        htmlspecialchars(
                            $registroVacuna[
                                "observaciones"
                            ] ?: "Sin observaciones",
                            ENT_QUOTES,
                            "UTF-8"
                        )
                    ) .
                    '
                    </td>
                ';

                echo "<td>";

                echo '
                    <button
                        type="button"
                        class="
                            btn
                            btn-outline-success
                            btn-sm
                            btn-editar-vacuna
                        "
                        data-id="' .
                    (int) $registroVacuna[
                        "id_vacunacion"
                    ] .
                    '"
                        data-animal="' .
                    htmlspecialchars(
                        $registroVacuna[
                            "nombre_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-nombre="' .
                    htmlspecialchars(
                        $registroVacuna[
                            "nombre_vacuna"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-fecha="' .
                    htmlspecialchars(
                        $registroVacuna[
                            "fecha_vacunacion"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-proxima="' .
                    htmlspecialchars(
                        $registroVacuna[
                            "fecha_proxima_vacuna"
                        ] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        data-observaciones="' .
                    htmlspecialchars(
                        $registroVacuna[
                            "observaciones"
                        ] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        title="Editar vacunación"
                    >
                        <i class="
                            bi
                            bi-pencil-square
                        "></i>
                    </button>
                ';

                echo '
                    <form
                        method="POST"
                        class="
                            d-inline
                            form-eliminar-vacuna
                        "
                    >
                        <input
                            type="hidden"
                            name="eliminar_vacuna"
                            value="1"
                        >

                        <input
                            type="hidden"
                            name="id_vacunacion"
                            value="' .
                    (int) $registroVacuna[
                        "id_vacunacion"
                    ] .
                    '"
                        >

                        <button
                            type="submit"
                            name="btn_eliminar_vacuna"
                            class="
                                btn
                                btn-outline-danger
                                btn-sm
                            "
                            title="Eliminar vacunación"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                ';

                echo "</td>";
                echo "</tr>";
            }
        }

        $tbodyHtml = ob_get_clean();

        ob_start();

        if ($totalPagVac > 1) {
            echo '
                <ul class="
                    pagination
                    justify-content-center
                ">
            ';

            echo '<li class="page-item ' .
                (
                    $pagActualVac <= 1
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualVac - 1) .
                '"
                data-tabla="vacunas"
            >
                Anterior
            </a>';

            echo "</li>";

            for (
                $i = 1;
                $i <= $totalPagVac;
                $i++
            ) {
                echo '<li class="page-item ' .
                    (
                        $pagActualVac === $i
                            ? "active"
                            : ""
                    ) .
                    '">';

                echo '<a
                    class="page-link"
                    href="#"
                    data-pagina="' .
                    $i .
                    '"
                    data-tabla="vacunas"
                >' .
                    $i .
                    "</a>";

                echo "</li>";
            }

            echo '<li class="page-item ' .
                (
                    $pagActualVac >=
                    $totalPagVac
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualVac + 1) .
                '"
                data-tabla="vacunas"
            >
                Siguiente
            </a>';

            echo "</li>";
            echo "</ul>";
        }

        $pagHtml = ob_get_clean();
    }


    /* =================================================
       DIETAS
    ================================================= */

    if ($tabla === "dietas") {
        ob_start();

        if ($errorCargaHorarios) {
            echo '
                <tr>
                    <td colspan="5">
                        <div class="estado-vacio">
                            <i class="bi bi-database-x"></i>
                            <h3>
                                No fue posible cargar los horarios
                            </h3>
                        </div>
                    </td>
                </tr>
            ';
        } elseif (count($listaHorarios) === 0) {
            echo '
                <tr>
                    <td colspan="5">
                        <div class="estado-vacio">
                            <i class="bi bi-calendar2-plus"></i>
                            <h3>No hay dietas registradas</h3>
                            <p>
                                Los horarios aparecerán aquí
                                después de registrar
                                la primera dieta.
                            </p>
                        </div>
                    </td>
                </tr>
            ';
        } else {
            foreach (
                $listaHorarios as $horario
            ) {
                $unidad = match (
                    $horario["unidad_medida"]
                ) {
                    "Kilogramos" => "kg",
                    "Litros" => "L",
                    "Unidades" => "uds",
                    default =>
                        $horario["unidad_medida"] ??
                        ""
                };

                echo "<tr>";

                echo '
                    <td>
                        <div class="animal-celda">
                            <span class="animal-icono">
                                <i class="fa-solid fa-paw"></i>
                            </span>

                            <div>
                                <strong>' .
                    htmlspecialchars(
                        $horario["nombre_animal"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</strong>

                                <small>' .
                    htmlspecialchars(
                        $horario["codigo_animal"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</small>
                            </div>
                        </div>
                    </td>
                ';

                echo "<td>" .
                    htmlspecialchars(
                        $horario["nombre_producto"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    "</td>";

                echo '
                    <td>
                        <span class="cantidad">' .
                    number_format(
                        (float) $horario[
                            "cantidad_recomendada"
                        ],
                        2,
                        ",",
                        "."
                    ) .
                    " " .
                    htmlspecialchars(
                        $unidad,
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '
                        </span>
                    </td>
                ';

                echo "<td><strong>" .
                    date(
                        "h:i A",
                        strtotime(
                            $horario["hora"]
                        )
                    ) .
                    "</strong></td>";

                echo "<td>";

                echo '
                    <div class="
                        d-flex
                        gap-1
                        justify-content-center
                    ">
                ';

                echo '
                    <button
                        type="button"
                        class="
                            btn
                            btn-outline-success
                            btn-sm
                            btn-editar-dieta
                        "
                        data-id="' .
                    (int) $horario[
                        "id_alimentacion"
                    ] .
                    '"
                        data-id-animal="' .
                    (int) $horario[
                        "id_animal"
                    ] .
                    '"
                        data-id-producto="' .
                    (int) $horario[
                        "id_inventarioZoo"
                    ] .
                    '"
                        data-cantidad="' .
                    (float) $horario[
                        "cantidad_recomendada"
                    ] .
                    '"
                        data-hora="' .
                    htmlspecialchars(
                        $horario["hora"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        title="Editar dieta"
                    >
                        <i class="
                            bi
                            bi-pencil-square
                        "></i>
                    </button>
                ';

                echo '
                    <button
                        type="button"
                        class="
                            btn
                            btn-outline-danger
                            btn-sm
                            px-2
                            py-1
                            btn-eliminar-dieta
                        "
                        data-id="' .
                    (int) $horario[
                        "id_alimentacion"
                    ] .
                    '"
                        data-animal="' .
                    htmlspecialchars(
                        $horario["nombre_animal"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '"
                        title="Eliminar horario"
                    >
                        <i class="
                            bi
                            bi-trash-fill
                        "></i>
                    </button>
                ';

                echo "</div>";
                echo "</td>";
                echo "</tr>";
            }
        }

        $tbodyHtml = ob_get_clean();

        ob_start();

        if ($totalPagDiet > 1) {
            echo '
                <ul class="
                    pagination
                    justify-content-center
                ">
            ';

            echo '<li class="page-item ' .
                (
                    $pagActualDiet <= 1
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualDiet - 1) .
                '"
                data-tabla="dietas"
            >
                Anterior
            </a>';

            echo "</li>";

            for (
                $i = 1;
                $i <= $totalPagDiet;
                $i++
            ) {
                echo '<li class="page-item ' .
                    (
                        $pagActualDiet === $i
                            ? "active"
                            : ""
                    ) .
                    '">';

                echo '<a
                    class="page-link"
                    href="#"
                    data-pagina="' .
                    $i .
                    '"
                    data-tabla="dietas"
                >' .
                    $i .
                    "</a>";

                echo "</li>";
            }

            echo '<li class="page-item ' .
                (
                    $pagActualDiet >=
                    $totalPagDiet
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualDiet + 1) .
                '"
                data-tabla="dietas"
            >
                Siguiente
            </a>';

            echo "</li>";
            echo "</ul>";
        }

        $pagHtml = ob_get_clean();
    }


    /* =================================================
       HISTORIAL DE ALERTAS
    ================================================= */

    if ($tabla === "alertas") {
        ob_start();

        if (count($historialAlertas) === 0) {
            echo '
                <tr>
                    <td colspan="3">
                        <div class="
                            estado-vacio
                            estado-vacio-compacto
                        ">
                            <i class="bi bi-bell-slash"></i>
                            <h3>
                                No hay alertas registradas
                            </h3>
                        </div>
                    </td>
                </tr>
            ';
        } else {
            foreach (
                $historialAlertas as $alertaGuardada
            ) {
                echo "<tr>";

                echo "<td><strong>" .
                    htmlspecialchars(
                        $alertaGuardada[
                            "nombre_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    '</strong>

                    <small class="
                        d-block
                        text-muted
                    ">' .
                    htmlspecialchars(
                        $alertaGuardada[
                            "codigo_animal"
                        ],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    "</small></td>";

                echo "<td>" .
                    htmlspecialchars(
                        $alertaGuardada["mensaje"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    "</td>";

                echo "<td>" .
                    date(
                        "d/m/Y h:i A",
                        strtotime(
                            $alertaGuardada[
                                "fecha_generada"
                            ]
                        )
                    ) .
                    "</td>";

                echo "</tr>";
            }
        }

        $tbodyHtml = ob_get_clean();

        ob_start();

        if ($totalPagAlert > 1) {
            echo '
                <ul class="
                    pagination
                    justify-content-center
                ">
            ';

            echo '<li class="page-item ' .
                (
                    $pagActualAlert <= 1
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualAlert - 1) .
                '"
                data-tabla="alertas"
            >
                Anterior
            </a>';

            echo "</li>";

            for (
                $i = 1;
                $i <= $totalPagAlert;
                $i++
            ) {
                echo '<li class="page-item ' .
                    (
                        $pagActualAlert === $i
                            ? "active"
                            : ""
                    ) .
                    '">';

                echo '<a
                    class="page-link"
                    href="#"
                    data-pagina="' .
                    $i .
                    '"
                    data-tabla="alertas"
                >' .
                    $i .
                    "</a>";

                echo "</li>";
            }

            echo '<li class="page-item ' .
                (
                    $pagActualAlert >=
                    $totalPagAlert
                        ? "disabled"
                        : ""
                ) .
                '">';

            echo '<a
                class="page-link"
                href="#"
                data-pagina="' .
                ($pagActualAlert + 1) .
                '"
                data-tabla="alertas"
            >
                Siguiente
            </a>';

            echo "</li>";
            echo "</ul>";
        }

        $pagHtml = ob_get_clean();
    }

    header(
        "Content-Type: application/json; charset=UTF-8"
    );

    echo json_encode(
        [
            "tbody" => $tbodyHtml,
            "paginacion" => $pagHtml
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit();
}