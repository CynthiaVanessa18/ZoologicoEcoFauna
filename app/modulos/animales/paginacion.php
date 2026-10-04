<?php if ($lista['paginas'] > 1): ?>
<nav class="paginacion" aria-label="Paginación de animales">
<?php if ($lista['pagina'] > 1): ?><a class="boton secundario" href="<?= escaparAnimal($rutaPagina . '?' . http_build_query(['buscar' => $busqueda, 'habitat' => $habitatFiltro, 'pagina' => $lista['pagina'] - 1])) ?>">Anterior</a><?php endif; ?>
<span>Página <?= $lista['pagina'] ?> de <?= $lista['paginas'] ?></span>
<?php if ($lista['pagina'] < $lista['paginas']): ?><a class="boton secundario" href="<?= escaparAnimal($rutaPagina . '?' . http_build_query(['buscar' => $busqueda, 'habitat' => $habitatFiltro, 'pagina' => $lista['pagina'] + 1])) ?>">Siguiente</a><?php endif; ?>
</nav>
<?php endif; ?>
