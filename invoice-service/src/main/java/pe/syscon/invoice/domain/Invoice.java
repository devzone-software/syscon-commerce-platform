package pe.syscon.invoice.domain;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.UUID;
@Entity public class Invoice {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false) public UUID orderId;
 @Column(nullable=false) public String type;
 @Column(nullable=false,unique=true) public String serialNumber;
 @Column(nullable=false) public String customerDocumentType;
 @Column(nullable=false) public String customerDocumentNumber;
 @Column(nullable=false) public String customerName;
 public String referencedSerialNumber;
 @Column(nullable=false) public String status="DRAFT";
 @Column(nullable=false,precision=13,scale=2) public BigDecimal total;
 public Instant createdAt=Instant.now();
 @Column(length=1000) public String error;
 protected Invoice() {}
 public Invoice(UUID orderId,String type,String serialNumber,String customerDocumentType,String customerDocumentNumber,String customerName,BigDecimal total) {this.orderId=orderId;this.type=type;this.serialNumber=serialNumber;this.customerDocumentType=customerDocumentType;this.customerDocumentNumber=customerDocumentNumber;this.customerName=customerName;this.total=total;}
}
