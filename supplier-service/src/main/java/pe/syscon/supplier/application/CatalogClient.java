package pe.syscon.supplier.application;
import java.math.BigDecimal;
import org.springframework.cloud.openfeign.FeignClient;
import org.springframework.web.bind.annotation.*;
@FeignClient(name="catalog",url="${clients.catalog.url:http://localhost:8082}")
public interface CatalogClient {
 record ProductInput(String sku,String name,BigDecimal price,int stock,String imageUrl,String category,String brand,boolean active) {}
 @PostMapping("/api/catalog/internal/products") void upsert(@RequestHeader("X-Service-Token") String token,@RequestBody ProductInput product);
}
