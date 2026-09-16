package pe.syscon.order.domain;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.*;
@Entity @Table(name="sales_order") public class SalesOrder {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false) public UUID customerId;
 @Column(nullable=false) public String status="CREATED";
 @Column(nullable=false,precision=13,scale=2) public BigDecimal total=BigDecimal.ZERO;
 public Instant createdAt=Instant.now();
 @OneToMany(mappedBy="order",cascade=CascadeType.ALL,orphanRemoval=true) public List<OrderDetail> details=new ArrayList<>();
 @Version public long version;
 protected SalesOrder() {} public SalesOrder(UUID customerId) {this.customerId=customerId;}
}
