package pe.syscon.catalog.domain;
import jakarta.persistence.*;
import java.util.UUID;
@Entity public class Inventory {
 @Id public UUID productId;
 @Column(nullable=false) public int available;
 @Version public long version;
 protected Inventory() {} public Inventory(UUID productId,int available) {this.productId=productId;this.available=available;}
}
