package pe.syscon.payment.application;
import java.math.BigDecimal;
import java.util.*;
import org.springframework.cloud.openfeign.FeignClient;
import org.springframework.web.bind.annotation.*;
import pe.syscon.common.ApiResponse;
@FeignClient(name="orders",url="${clients.orders.url:http://localhost:8084}")
public interface OrderClient {
 record Detail(UUID productId,String sku,String name,int quantity,BigDecimal unitPrice) {}
 record Order(UUID id,UUID customerId,String status,BigDecimal total,List<Detail> details) {}
 @GetMapping("/api/orders/internal/{id}") ApiResponse<Order> get(@RequestHeader("X-Service-Token") String token,@PathVariable UUID id);
 @PostMapping("/api/orders/internal/{id}/paid") void paid(@RequestHeader("X-Service-Token") String token,@PathVariable UUID id);
}
