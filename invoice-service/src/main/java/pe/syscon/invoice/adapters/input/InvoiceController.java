package pe.syscon.invoice.adapters.input;
import jakarta.validation.Valid;
import jakarta.validation.constraints.*;
import java.util.UUID;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.*;
import pe.syscon.common.ApiResponse;
import pe.syscon.invoice.application.InvoiceService;
@RestController @RequestMapping("/api/invoices") @PreAuthorize("hasAuthority('invoices:submit')") public class InvoiceController {
 private final InvoiceService service;public InvoiceController(InvoiceService service) {this.service=service;}
 public record Create(@NotNull UUID orderId,@NotBlank String type,@NotBlank String serialNumber,@NotBlank String customerDocumentType,@NotBlank String customerDocumentNumber,@NotBlank String customerName,String referencedSerialNumber) {}
 @PostMapping public ApiResponse<InvoiceService.View> create(@Valid @RequestBody Create body) {return ApiResponse.ok("Comprobante creado",service.create(new InvoiceService.Create(body.orderId(),body.type(),body.serialNumber(),body.customerDocumentType(),body.customerDocumentNumber(),body.customerName(),body.referencedSerialNumber())));}
 @GetMapping("/{id}") public ApiResponse<InvoiceService.View> get(@PathVariable UUID id) {return ApiResponse.ok("Comprobante",service.get(id));}
 @PostMapping("/{id}/submit") public ApiResponse<InvoiceService.View> submit(@PathVariable UUID id) {return ApiResponse.ok("Envío procesado",service.submit(id));}
}
