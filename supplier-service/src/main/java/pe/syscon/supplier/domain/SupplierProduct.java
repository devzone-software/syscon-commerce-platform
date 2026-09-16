package pe.syscon.supplier.domain;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.util.UUID;
@Entity @Table(uniqueConstraints=@UniqueConstraint(columnNames={"supplier_id","sku"})) public class SupplierProduct {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @ManyToOne(optional=false) public Supplier supplier;
 @Column(nullable=false) public String sku;
 @Column(nullable=false) public String name;
 public BigDecimal price;
 public int stock;
 public String imageUrl;
 public String brand;
 public String category;
 protected SupplierProduct() {} public SupplierProduct(Supplier supplier,String sku) {this.supplier=supplier;this.sku=sku;}
}
