package pe.syscon.order;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.cloud.openfeign.EnableFeignClients;
@SpringBootApplication(scanBasePackages="pe.syscon")
@EnableFeignClients
public class OrderApplication { public static void main(String[] args) { SpringApplication.run(OrderApplication.class, args); } }
