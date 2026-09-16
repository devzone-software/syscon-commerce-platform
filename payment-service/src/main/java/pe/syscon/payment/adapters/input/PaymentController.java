package pe.syscon.payment.adapters.input;
import jakarta.validation.Valid;
import jakarta.validation.constraints.*;
import java.util.*;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.*;
import pe.syscon.common.ApiResponse;
import pe.syscon.payment.application.PaymentService;
import pe.syscon.payment.domain.Payment;
@RestController @RequestMapping("/api/payments") public class PaymentController {
 private final PaymentService service;public PaymentController(PaymentService service) {this.service=service;}
 public record Create(@NotNull UUID orderId,@NotBlank String provider) {}
 public record Confirm(@NotBlank String reference) {}
 @PostMapping public ApiResponse<Payment> create(Authentication auth,@Valid @RequestBody Create body) {return ApiResponse.ok("Pago creado",service.create(body.orderId(),UUID.fromString(auth.getName()),body.provider()));}
 @GetMapping("/{id}") public ApiResponse<Payment> get(Authentication auth,@PathVariable UUID id) {return ApiResponse.ok("Pago",service.get(id,UUID.fromString(auth.getName()),auth.getAuthorities().stream().anyMatch(a -> a.getAuthority().equals("payments:confirm"))));}
 @PostMapping("/{id}/confirm") @PreAuthorize("hasAuthority('payments:confirm')") public ApiResponse<Payment> confirm(@PathVariable UUID id,@Valid @RequestBody Confirm body) {return ApiResponse.ok("Pago confirmado",service.confirm(id,body.reference()));}
}
