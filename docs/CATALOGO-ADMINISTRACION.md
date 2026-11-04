# Catálogo público y administrativo

Cambios coordinados con `syscon-commerce-web`, 4 de noviembre de 2026.

- `GET /api/catalog/products`: solo productos activos; `stock` son las unidades disponibles
  (`stock físico − reservas`).
- `GET /api/catalog/products/{id}`: un producto inactivo responde 404.
- `GET /api/catalog/admin/products`: exige JWT y `catalog:write`. Incluye activos e inactivos,
  `stock` disponible, `physicalStock` físico y `reservedStock` reservado. Esta respuesta no se
  almacena en la caché pública del BFF.
- `POST /api/catalog/products`: el campo histórico `stock` sigue significando stock físico.
  El editor y la exportación CSV deben usar `physicalStock`, nunca copiar el `stock` disponible.
  No se acepta reducir stock físico por debajo de las reservas.

`description` y `specifications` son textos opcionales de hasta 5000 caracteres. Se muestran como
texto, sin interpretar HTML. Omitir un campo conserva su valor; enviarlo como `null` lo vacía.
Los proveedores que todavía no incluyen esos campos no borran el contenido escrito por el equipo.

Ejecutar las migraciones antes de actualizar la web. La migración de detalles añade dos columnas
nullable, sin modificar datos de productos existentes. Otra migración amplía `order_events.type`
a 64 caracteres para admitir el evento de cobro duplicado; su reversión conserva la longitud
ampliada para no truncar eventos registrados.

Las pruebas de `CatalogAdministrationTest` cubren acceso, stock físico con reservas, ocultación,
reactivación y conservación de detalles ante importaciones anteriores.
