package pe.syscon.invoice.application;

import java.io.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.zip.ZipInputStream;
import javax.xml.XMLConstants;
import javax.xml.parsers.DocumentBuilderFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import pe.syscon.common.ApiException;
import pe.syscon.invoice.domain.*;
import pe.syscon.invoice.infrastructure.*;

@Service public class InvoiceService {
 private final InvoiceRepository invoices;private final XmlDocumentRepository xmls;private final CdrResponseRepository cdrs;private final OrderClient orders;private final UblXmlBuilder builder;private final SunatGateway gateway;private final String token,ruc;
 public InvoiceService(InvoiceRepository invoices,XmlDocumentRepository xmls,CdrResponseRepository cdrs,OrderClient orders,UblXmlBuilder builder,SunatGateway gateway,@Value("${security.service-token}") String token,@Value("${sunat.ruc:}") String ruc) {this.invoices=invoices;this.xmls=xmls;this.cdrs=cdrs;this.orders=orders;this.builder=builder;this.gateway=gateway;this.token=token;this.ruc=ruc;}
 public record Create(UUID orderId,String type,String serialNumber,String customerDocumentType,String customerDocumentNumber,String customerName,String referencedSerialNumber) {}
 public record View(UUID id,UUID orderId,String type,String serialNumber,String status,String error,String sunatCode,String sunatDescription) {}
 @Transactional public View create(Create input) {
  if(!List.of("01","03","07","08").contains(input.type())) throw new ApiException(HttpStatus.BAD_REQUEST,"Tipo de comprobante inválido");
  if(input.serialNumber()==null||!input.serialNumber().matches("[FB][A-Z0-9]{3}-[1-9][0-9]{0,7}")) throw new ApiException(HttpStatus.BAD_REQUEST,"Serie y correlativo inválidos");
  if((input.type().equals("01")&&!input.serialNumber().startsWith("F"))||(input.type().equals("03")&&!input.serialNumber().startsWith("B"))) throw new ApiException(HttpStatus.BAD_REQUEST,"Serie incompatible");
  if(List.of("07","08").contains(input.type()) && (input.referencedSerialNumber()==null||input.referencedSerialNumber().isBlank())) throw new ApiException(HttpStatus.BAD_REQUEST,"Documento de referencia requerido");
  if(List.of("01","03").contains(input.type()) && invoices.existsByOrderIdAndTypeIn(input.orderId(),List.of("01","03"))) throw new ApiException(HttpStatus.CONFLICT,"Pedido ya facturado");
  if(List.of("07","08").contains(input.type())) {
   var original=invoices.findBySerialNumber(input.referencedSerialNumber()).orElseThrow(() -> new ApiException(HttpStatus.BAD_REQUEST,"Comprobante de referencia no encontrado"));
   if(!original.orderId.equals(input.orderId())||!"ACCEPTED".equals(original.status)) throw new ApiException(HttpStatus.CONFLICT,"Comprobante de referencia no aceptado");
  }
  var order=orders.get(token,input.orderId()).data();
  if(order==null||!List.of("PAID","PROCESSING","SHIPPED","DELIVERED").contains(order.status())) throw new ApiException(HttpStatus.CONFLICT,"Pedido no pagado");
  var invoice=new Invoice(input.orderId(),input.type(),input.serialNumber(),input.customerDocumentType(),input.customerDocumentNumber(),input.customerName(),order.total());
  invoice.referencedSerialNumber=input.referencedSerialNumber();invoice=invoices.save(invoice);
  xmls.save(new XmlDocument(invoice,builder.build(invoice,order)));
  return view(invoice);
 }
 @Transactional(readOnly=true) public View get(UUID id) {return view(find(id));}
 @Transactional public View submit(UUID id) {
  var invoice=find(id);if("ACCEPTED".equals(invoice.status)) return view(invoice);
  if(!List.of("DRAFT","FAILED").contains(invoice.status)) throw new ApiException(HttpStatus.CONFLICT,"Estado de comprobante inválido");
  if(!gateway.configured()) throw new ApiException(HttpStatus.SERVICE_UNAVAILABLE,"Credenciales SUNAT no configuradas");
  var document=xmls.findByInvoiceId(id).orElseThrow();
  try {
   if(document.signedXml==null) {document.signedXml=gateway.sign(document.generatedXml);xmls.save(document);}
   String cdr=gateway.sendBill(ruc+"-"+invoice.type+"-"+invoice.serialNumber,document.signedXml);
   String[] result=parseCdr(cdr);cdrs.save(new CdrResponse(invoice,cdr,result[0],result[1]));
   invoice.status="0".equals(result[0])?"ACCEPTED":"REJECTED";invoice.error=null;
  } catch(Exception e) {invoice.status="FAILED";invoice.error=(e.getMessage()==null?e.getClass().getSimpleName():e.getMessage()).substring(0,Math.min(1000,e.getMessage()==null?e.getClass().getSimpleName().length():e.getMessage().length()));}
  return view(invoices.save(invoice));
 }
 private String[] parseCdr(String base64) throws Exception {
  byte[] zip=Base64.getDecoder().decode(base64);if(zip.length>5_000_000) throw new IOException("CDR demasiado grande");
  try(var stream=new ZipInputStream(new ByteArrayInputStream(zip))) {
   if(stream.getNextEntry()==null) throw new IOException("CDR vacío");
   var bytes=stream.readNBytes(5_000_001);if(bytes.length>5_000_000) throw new IOException("XML CDR demasiado grande");
   var f=DocumentBuilderFactory.newInstance();f.setNamespaceAware(true);f.setFeature("http://apache.org/xml/features/disallow-doctype-decl",true);f.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING,true);
   var doc=f.newDocumentBuilder().parse(new ByteArrayInputStream(bytes));
   var codes=doc.getElementsByTagNameNS("*","ResponseCode");var descriptions=doc.getElementsByTagNameNS("*","Description");
   if(codes.getLength()==0) throw new IOException("CDR sin código");return new String[]{codes.item(0).getTextContent(),descriptions.getLength()==0?"":descriptions.item(0).getTextContent()};
  }
 }
 private Invoice find(UUID id) {return invoices.findById(id).orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND,"Comprobante no encontrado"));}
 private View view(Invoice i) {var c=cdrs.findByInvoiceId(i.id);return new View(i.id,i.orderId,i.type,i.serialNumber,i.status,i.error,c.map(x -> x.responseCode).orElse(null),c.map(x -> x.description).orElse(null));}
}
