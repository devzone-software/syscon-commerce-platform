package pe.syscon.order.adapters.input;
import java.util.*;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.*;
import pe.syscon.common.*;
import pe.syscon.order.application.OrderService;
@RestController @RequestMapping("/api/orders") public class OrderController {
 private final OrderService service;private final InternalToken internal;
 public OrderController(OrderService service,InternalToken internal) {this.service=service;this.internal=internal;}
 public record Create(List<OrderService.Line> items) {}
 @PostMapping public ApiResponse<OrderService.View> create(Authentication auth,@RequestBody Create body) {return ApiResponse.ok("Pedido creado",service.create(UUID.fromString(auth.getName()),body.items()));}
 @GetMapping public ApiResponse<List<OrderService.View>> list(Authentication auth) {return ApiResponse.ok("Pedidos",service.list(UUID.fromString(auth.getName())));}
 @GetMapping("/{id}") public ApiResponse<OrderService.View> get(Authentication auth,@PathVariable UUID id) {var o=service.get(id);if(!o.customerId().toString().equals(auth.getName())&&!auth.getAuthorities().stream().anyMatch(a->a.getAuthority().equals("orders:manage"))) throw new ApiException(org.springframework.http.HttpStatus.FORBIDDEN,"Acceso denegado");return ApiResponse.ok("Pedido",o);}
 @PatchMapping("/{id}/status/{status}") @PreAuthorize("hasAuthority('orders:manage')") public ApiResponse<OrderService.View> status(@PathVariable UUID id,@PathVariable String status) {return ApiResponse.ok("Estado actualizado",service.transition(id,status));}
 @PostMapping("/internal/{id}/paid") public ApiResponse<OrderService.View> paid(@RequestHeader("X-Service-Token") String token,@PathVariable UUID id) {internal.verify(token);return ApiResponse.ok("Pago confirmado",service.markPaid(id));}
 @GetMapping("/internal/{id}") public ApiResponse<OrderService.View> internalGet(@RequestHeader("X-Service-Token") String token,@PathVariable UUID id) {internal.verify(token);return ApiResponse.ok("Pedido",service.get(id));}
}
