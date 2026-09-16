package pe.syscon.invoice.application;
import static org.junit.jupiter.api.Assertions.*;
import java.io.StringReader;
import java.math.BigDecimal;
import java.util.*;
import javax.xml.parsers.DocumentBuilderFactory;
import org.junit.jupiter.api.Test;
import org.xml.sax.InputSource;
import pe.syscon.invoice.domain.Invoice;
class UblXmlBuilderTest {
 @Test void producesWellFormedInvoiceWithAmountAndParties() throws Exception {
  var order=new OrderClient.Order(UUID.randomUUID(),UUID.randomUUID(),"PAID",new BigDecimal("49.90"),List.of(new OrderClient.Detail(UUID.randomUUID(),"SKU","Mouse",1,new BigDecimal("49.90"))));
  var invoice=new Invoice(order.id(),"01","F001-1","6","20123456789","Cliente SAC",order.total());
  var xml=new UblXmlBuilder("20987654321","SYSCON SAC").build(invoice,order);
  var factory=DocumentBuilderFactory.newInstance();factory.setNamespaceAware(true);
  var document=factory.newDocumentBuilder().parse(new InputSource(new StringReader(xml)));
  assertEquals("Invoice",document.getDocumentElement().getLocalName());
  assertTrue(xml.contains("F001-1"));assertTrue(xml.contains("49.90"));assertTrue(xml.contains("Cliente SAC"));
 }
}
