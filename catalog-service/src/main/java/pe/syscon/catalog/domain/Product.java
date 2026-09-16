package pe.syscon.catalog.domain;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.util.UUID;
@Entity public class Product {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false,unique=true) public String sku;
 @Column(nullable=false) public String name;
 @Column(nullable=false,precision=13,scale=2) public BigDecimal price;
 public String imageUrl;
 public boolean active=true;
 @ManyToOne public Category category;
 @ManyToOne public Brand brand;
 @Version public long version;
 protected Product() {} public Product(String sku,String name,BigDecimal price) {this.sku=sku;this.name=name;this.price=price;}
}
