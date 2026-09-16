package pe.syscon.invoice.domain;
import jakarta.persistence.*;
import java.util.UUID;
@Entity public class CdrResponse {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @OneToOne(optional=false) public Invoice invoice;
 @Lob public String zippedCdrBase64;
 public String responseCode;
 @Column(length=1000) public String description;
 protected CdrResponse() {} public CdrResponse(Invoice invoice,String cdr,String code,String description) {this.invoice=invoice;this.zippedCdrBase64=cdr;this.responseCode=code;this.description=description;}
}
