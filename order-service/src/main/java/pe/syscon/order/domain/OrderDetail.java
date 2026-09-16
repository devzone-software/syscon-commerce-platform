package pe.syscon.order.domain;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.util.UUID;
@Entity public class OrderDetail {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @ManyToOne(optional=false) public SalesOrder order;
 @Column(nullable=false) public UUID productId;
 @Column(nullable=false) public String sku;
 @Column(nullable=false) public String name;
 public int quantity;
 @Column(nullable=false,precision=13,scale=2) public BigDecimal unitPrice;
 protected OrderDetail() {} public OrderDetail(SalesOrder order,UUID productId,String sku,String name,int quantity,BigDecimal unitPrice) {this.order=order;this.productId=productId;this.sku=sku;this.name=name;this.quantity=quantity;this.unitPrice=unitPrice;}
}
