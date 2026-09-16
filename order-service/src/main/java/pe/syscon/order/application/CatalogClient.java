package pe.syscon.order.application;
import java.math.BigDecimal;
import java.util.UUID;
import org.springframework.cloud.openfeign.FeignClient;
import org.springframework.web.bind.annotation.*;
import pe.syscon.common.ApiResponse;
@FeignClient(name="catalog",url="${clients.catalog.url:http://localhost:8082}")
public interface CatalogClient {
 record Product(UUID id,String sku,String name,BigDecimal price,int stock,String imageUrl,String category,String brand,boolean active) {}
 @GetMapping("/api/catalog/products/{id}") ApiResponse<Product> get(@PathVariable UUID id);
}
