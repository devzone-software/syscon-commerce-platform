package pe.syscon.supplier.adapters.input;
import jakarta.validation.Valid;
import jakarta.validation.constraints.*;
import java.util.*;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.*;
import pe.syscon.common.ApiResponse;
import pe.syscon.supplier.application.SupplierService;
import pe.syscon.supplier.domain.*;
@RestController @RequestMapping("/api/suppliers") @PreAuthorize("hasAuthority('suppliers:manage')")
public class SupplierController {
 private final SupplierService service;public SupplierController(SupplierService service) {this.service=service;}
 public record SupplierInput(@NotBlank String name,@NotBlank String feedUrl,@Min(15) int minIntervalMinutes) {}
 @PostMapping public ApiResponse<Supplier> add(@Valid @RequestBody SupplierInput input) {return ApiResponse.ok("Proveedor creado",service.add(input.name(),input.feedUrl(),input.minIntervalMinutes()));}
 @GetMapping public ApiResponse<List<Supplier>> list() {return ApiResponse.ok("Proveedores",service.list());}
 @PostMapping("/{id}/run") public ApiResponse<ScrapingJob> run(@PathVariable UUID id) {return ApiResponse.ok("Sincronización terminada",service.run(id));}
 @GetMapping("/jobs") public ApiResponse<List<ScrapingJob>> jobs() {return ApiResponse.ok("Ejecuciones",service.jobs());}
 @GetMapping("/jobs/{id}/history") public ApiResponse<List<ScrapingHistory>> history(@PathVariable UUID id) {return ApiResponse.ok("Historial",service.history(id));}
}
