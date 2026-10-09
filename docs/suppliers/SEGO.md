# Evaluación de integración: SEGO

Fecha: 2026-09-16. Fuente principal: [tienda pública](https://www.sego.com.pe/shop).

## Hallazgos

- La tienda usa Odoo, muestra 852 artículos en la consulta realizada y pagina 20 artículos por vista mediante rutas `/shop/page/{n}`. El número de artículos cambia con el tiempo.
- La página pública expone nombre, SKU, enlace de detalle e imagen del producto. El detalle también muestra marca en ejemplos revisados.
- El menú tiene categorías jerárquicas, con rutas `/shop/category/{slug}-{id}`. Se observaron 94 enlaces de categoría en el HTML de la portada; algunos nombres se repiten con identificadores distintos. Se debe conservar el ID externo y la relación padre-hijo, sin usar solo el nombre como clave.
- El precio se oculta al visitante y pide iniciar sesión o registrarse. El stock depende de la sucursal elegida y no aparece como una cantidad confiable en la vista pública.
- No se identificó un feed CSV/XML ni una API pública documentada para productos, precios y stock en la revisión. La web publica un [catálogo informativo](https://www.sego.com.pe/catalogo), pero no se confirmó que sea una exportación estructurada.
- Su [robots.txt](https://www.sego.com.pe/robots.txt) permite `/shop`, categorías, páginas e imágenes al agente genérico, pero bloquea expresamente `ChatGPT-User` y otros agentes de IA. También excluye rutas de sesión, compra y parámetros de búsqueda.

## Decisión de integración

Solicitar a SEGO un feed/API autorizado para distribuidores que incluya SKU, precio, moneda, stock por sucursal, marca, categoría e imágenes. El sitio es B2B: usar valores públicos como si fueran precio o inventario de venta produciría datos incorrectos. No usar rutas internas de Odoo ni automatizar el inicio de sesión sin acuerdo del proveedor.

Cuando exista el contrato de datos, implementar un adaptador SEGO aislado en `app/Modules/Suppliers` que normalice por SKU e ID externo, conserve la jerarquía de categorías, aplique límites de frecuencia y envíe cambios idempotentes a `app/Modules/Catalog`. El importador actual solo consume CSV con columnas `sku;name;price;stock;imageUrl;brand;category`; no procesa HTML de SEGO.
