package pe.syscon.catalog.application;

import jakarta.validation.constraints.*;
import java.math.BigDecimal;
import java.util.*;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import pe.syscon.catalog.domain.*;
import pe.syscon.catalog.infrastructure.*;
import pe.syscon.common.ApiException;

@Service
public class CatalogService {
 private final ProductRepository products;private final InventoryRepository inventory;private final CategoryRepository categories;private final BrandRepository brands;
 public CatalogService(ProductRepository products,InventoryRepository inventory,CategoryRepository categories,BrandRepository brands) {this.products=products;this.inventory=inventory;this.categories=categories;this.brands=brands;}
 public record ProductInput(@NotBlank String sku,@NotBlank String name,@NotNull @DecimalMin("0.00") BigDecimal price,@Min(0) int stock,String imageUrl,String category,String brand,boolean active) {}
 public record ProductView(UUID id,String sku,String name,BigDecimal price,int stock,String imageUrl,String category,String brand,boolean active) {}
 @Transactional public ProductView upsert(ProductInput input) {
  Product p=products.findBySku(input.sku()).orElseGet(() -> new Product(input.sku(),input.name(),input.price()));
  p.name=input.name();p.price=input.price();p.imageUrl=input.imageUrl();p.active=input.active();
  if(input.category()!=null && !input.category().isBlank()) p.category=categories.findByNameIgnoreCase(input.category()).orElseGet(() -> categories.save(new Category(input.category())));
  if(input.brand()!=null && !input.brand().isBlank()) p.brand=brands.findByNameIgnoreCase(input.brand()).orElseGet(() -> brands.save(new Brand(input.brand())));
  p=products.save(p);UUID productId=p.id;Inventory stock=inventory.findById(productId).orElseGet(() -> new Inventory(productId,0));stock.available=input.stock();inventory.save(stock);return view(p,input.stock());
 }
 @Transactional(readOnly=true) public ProductView get(UUID id) { Product p=products.findById(id).orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND,"Producto no encontrado"));return view(p,inventory.findById(id).map(i -> i.available).orElse(0)); }
 @Transactional(readOnly=true) public List<ProductView> list() {return products.findAll().stream().filter(p -> p.active).map(p -> view(p,inventory.findById(p.id).map(i -> i.available).orElse(0))).toList();}
 @Transactional(readOnly=true) public List<String> categories() {return categories.findAll().stream().map(c -> c.name).toList();}
 @Transactional(readOnly=true) public List<String> brands() {return brands.findAll().stream().map(b -> b.name).toList();}
 private ProductView view(Product p,int stock) {return new ProductView(p.id,p.sku,p.name,p.price,stock,p.imageUrl,p.category==null?null:p.category.name,p.brand==null?null:p.brand.name,p.active);}
}
