package pe.syscon.payment.domain;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.UUID;
@Entity @Table(uniqueConstraints=@UniqueConstraint(columnNames={"orderId","provider"})) public class Payment {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false) public UUID orderId;
 @Column(nullable=false) public UUID customerId;
 @Column(nullable=false) public String provider;
 @Column(nullable=false) public String status="PENDING";
 @Column(nullable=false,precision=13,scale=2) public BigDecimal amount;
 public String externalReference;
 public String paymentUrl;
 public Instant createdAt=Instant.now();
 @Version public long version;
 protected Payment() {} public Payment(UUID orderId,UUID customerId,String provider,BigDecimal amount) {this.orderId=orderId;this.customerId=customerId;this.provider=provider;this.amount=amount;}
}
