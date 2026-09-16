package pe.syscon.catalog.adapters.input;

import jakarta.validation.Valid;
import java.util.*;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.*;
import pe.syscon.catalog.application.CatalogService;
import pe.syscon.common.*;

@RestController @RequestMapping("/api/catalog")
public class CatalogController {
 private final CatalogService catalog;private final InternalToken internal;
 public CatalogController(CatalogService catalog,InternalToken internal) {this.catalog=catalog;this.internal=internal;}
 @GetMapping("/products") public ApiResponse<List<CatalogService.ProductView>> list() {return ApiResponse.ok("Productos",catalog.list());}
 @GetMapping("/products/{id}") public ApiResponse<CatalogService.ProductView> get(@PathVariable UUID id) {return ApiResponse.ok("Producto",catalog.get(id));}
 @GetMapping("/categories") public ApiResponse<List<String>> categories() {return ApiResponse.ok("Categorías",catalog.categories());}
 @GetMapping("/brands") public ApiResponse<List<String>> brands() {return ApiResponse.ok("Marcas",catalog.brands());}
 @PostMapping("/products") @PreAuthorize("hasAuthority('catalog:write')") public ApiResponse<CatalogService.ProductView> create(@Valid @RequestBody CatalogService.ProductInput body) {return ApiResponse.ok("Producto guardado",catalog.upsert(body));}
 @PostMapping("/internal/products") public ApiResponse<CatalogService.ProductView> importProduct(@RequestHeader("X-Service-Token") String token,@Valid @RequestBody CatalogService.ProductInput body) {internal.verify(token);return ApiResponse.ok("Producto sincronizado",catalog.upsert(body));}
}
