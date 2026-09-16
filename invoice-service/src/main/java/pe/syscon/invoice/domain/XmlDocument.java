package pe.syscon.invoice.domain;
import jakarta.persistence.*;
import java.util.UUID;
@Entity public class XmlDocument {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @OneToOne(optional=false) public Invoice invoice;
 @Lob public String generatedXml;
 @Lob public String signedXml;
 protected XmlDocument() {} public XmlDocument(Invoice invoice,String generatedXml) {this.invoice=invoice;this.generatedXml=generatedXml;}
}
